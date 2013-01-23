<?php

define('SUCCESS_STRING', 'setupscript_success');
define('FAIL_STRING', 'setupscript_fail');

$_SC_LOG_FILE = NULL;

function _Log($msg) {
  global $_SC_LOG_FILE;

  if (!isset($GLOBALS['LOG_OFF'])) {
    if ($_SC_LOG_FILE == NULL) {
      $SV = SetupVars::get();
      $_SC_LOG_FILE = $SV->INSTALL_DIR . '/piscript.log';
    }

    $args = func_get_args();
    array_shift($args);

    // convert objects to str
    foreach ($args as &$a) {
      if (is_object($a) || is_array($a)) {
        $a = print_r($a, true);
      }
    }

    $str = date('[H:i:s]') . ' ' . (!empty($args) ? @vsprintf($msg, $args) : $msg) . "\n";

    file_put_contents($_SC_LOG_FILE, $str, FILE_APPEND);
  }
}


function acqThrow() {
  $args = func_get_args();
  $msg = array_shift($args);
  $text = !empty($args) ? @vsprintf($msg, $args) : $msg;
  $db = debug_backtrace();
  $caller = $db[1];
  $func = isset($caller['class']) ? "{$caller['class']}::{$caller['function']}()" : "{$caller['function']}()";
  throw new Exception($func . ' ' . $text);
}


class SetupVars {
  public $SITE_NAME;
  public $ADM_USERNAME;
  public $ADM_PASSWORD;
  public $ADM_REALNAME;
  public $ADM_EMAIL;
  public $DB_NAME;
  public $DB_USERNAME;
  public $DB_PASSWORD;
  public $APACHE_PORT;
  public $MYSQL_PORT;
  public $SITE_DIR;
  public $INSTALL_DIR;
  public $PLATFORM_NAME;
  public $AD_VER;
  public $SYS_USER;
  public $UPGRADE;
  /**
   * @var UpgradeContext 
   */
  public $_upgCtx;

  public function LoadSetupVars($file = null) {
    if (! $file)
      $file = dirname(__FILE__) . '/setupvars';
    $v = Util::ParseIniFile($file);
    foreach ($v['vars'] as $name => $val) {
      $this->$name = $val;
    }
  }

  public function Save($file = null) {
    if (! $file)
      $file = dirname(__FILE__) . '/setupvars';
    
    $str = "[vars]\n";
    foreach ($this as $n => $v) {
      if (substr($n, 0, 1) != '_')
        $str .= "$n=$v\n";
    }
    file_put_contents($file, $str);
  }

  /**
   * @return SetupVars
   */
  public static function get() {
    if (self::$_inst == null) {
      self::$_inst = new SetupVars();
      self::$_inst->LoadSetupVars();
    }
    return self::$_inst;
  }
  
  //private function __construct ()
  //{ }
  


  private static $_inst;
}

class UpgradeContext {
  public $oldCpIniPathS;
  public $oldCpIniPathD;
  public $newCpIniPathS;
  public $newCpIniPathD;
  
  public $instBackupDir;
  public $siteBackupDir;
  public $upgradeOldVersion = false;

  public function OldCpIniS() {
    return Util::ParseIniFile($this->oldCpIniPathS);
  }

  public function OldCpIniD() {
    return Util::ParseIniFile($this->oldCpIniPathD);
  }

  public function NewCpIniS() {
    return Util::ParseIniFile($this->newCpIniPathS);
  }

  public function NewCpIniD() {
    return Util::ParseIniFile($this->newCpIniPathD);
  }
}



class Setup {
  // some OSX consts
  const ACQUIA_GROUP = 'acquia_drupal';
  const APACHE_USER = 'ad_apache';
  const MYSQL_USER = 'ad_mysql';
  const APACHE_PLIST = 'com.acquia.drupal.apache';
  const MYSQL_PLIST = 'com.acquia.drupal.mysql';
  const PLIST_DIR = '/Library/LaunchDaemons';

  //static private  $upgradeMode = false;
  


  public static function Run() {
    $sv = SetupVars::get();
    
    _Log('Post install script started ' . date('m/d/Y'));
    
    //  See AN-5421. User is no longer asked for database settings, so we hardcode them here.
    $sv->DB_NAME = 'acquia_drupal';
    $sv->DB_USERNAME = 'drupaluser';
    $sv->DB_PASSWORD = ''; //empty password
    


    // detect upgrade mode
    $sv->UPGRADE = ($sv->UPGRADE == 'upgrade');
    _Log("Upgrade:" . (int) $sv->UPGRADE);
    

    // gather old settings
    if ($sv->UPGRADE) {
      $sv->_upgCtx = self::UpgradeCreateContext();
      
      // detect ports
      $oldCpIniD = $sv->_upgCtx->OldCpIniD();
      $sv->APACHE_PORT = $oldCpIniD['services']['apachePort'];
      $sv->MYSQL_PORT = $oldCpIniD['services']['mysqlPort'];
      
      $sv->ADM_EMAIL = self::UpgradeDetectOldAdmEmail();
      $sv->Save(); // just in case...
    }
    
    // substitute vars in config files
    self::SubstituteConfigVars();

    // log config contents. may be helpful for debugging
    if ($sv->UPGRADE) {
      _Log("Old control panel configs:\n%s\n=== static ===%s\n%s\n=== dynamic ===\n%s", $sv->_upgCtx->oldCpIniPathS, print_r($sv->_upgCtx->OldCpIniS(), true), $sv->_upgCtx->oldCpIniPathD, print_r($sv->_upgCtx->OldCpIniD(), true));
      _Log("New control panel configs:\n%s\n=== static ===%s\n%s\n=== dynamic ===\n%s", $sv->_upgCtx->newCpIniPathS, print_r($sv->_upgCtx->NewCpIniS(), true), $sv->_upgCtx->newCpIniPathD, print_r($sv->_upgCtx->NewCpIniD(), true));
    }
    //
    // merge folders
    //
    if ($sv->UPGRADE) {
      // merge install folder            
      self::UpgradeMergeInstallDir();
      
      //merge site folder            
      $fileList = self::UpgradeMergeSiteDir();
      if ($sv->_upgCtx->upgradeOldVersion) {
        _Log(empty($fileList) ? "No old files copied" : "Some files copied from the old site folder:\n" . implode("\n", $fileList));
      }
      else {
        _Log(empty($fileList) ? "No modified files detected" : "Modified files detected. Backups created:\n" . implode("\n", $fileList));
      }
      
    // remove backups
    // Util::RmDir( $sv->INSTALL_DIR.'/rollbackBackupDirectory' );
    }
    
    //
    // osx permission fix
    //
    if ($sv->PLATFORM_NAME == 'osx') {
      $stdout = $stderr = null;
      
      $userName = Util::GetRealUser();
      // current user and admin group are the owners
      Util::ExecC(sprintf("chown -R %s:admin %s", escapeshellarg($userName), escapeshellarg($sv->INSTALL_DIR)), $stdout);
      // readwrite for the user and group, nothing for others
      Util::ExecC("chmod -R ug+rw,o-rwx " . escapeshellarg($sv->INSTALL_DIR), $stdout);
      // user and group can list folders
      Util::ExecC(sprintf("find %s -type d -exec chmod ug+x {} \;", escapeshellarg($sv->INSTALL_DIR)), $stdout);
      
      //correct file permissions for Site dir
      _Log("correct file permissions for Site install dir");
      if ($userName && $userName != 'root') {
        $fullName = escapeshellarg($userName);
        if (OSXUtil::OSUserExists($userName) && ($groupName = OSXUtil::GetOSGroupNameByID(OSXUtil::GetOSUserGroupID($userName))) != null) {
          $fullName = escapeshellarg($userName) . ':' . escapeshellarg($groupName);
        }

        Util::Exec("chown -R $fullName " . escapeshellarg($sv->SITE_DIR), $stdout, $stderr);
      }
    }
    //
    // Copy dlls from php folder to apache bin folder to resolve possible dll hell issue when
    // user has dlls like libeay32.dll in their system folder
    //
    else if ($sv->PLATFORM_NAME == 'windows') {
    /*
      // Check if we install on an NTFS disk
      $out = '';
      Util::ExecC('fsutil fsinfo volumeinfo ' . substr($sv->INSTALL_DIR, 0, 3), $out);
      $matches = array();
      preg_match('/file system name\s*:\s*(\w+)/i', $out, $matches);
      $fstype = strtolower($matches[1]);
      $copyfunc = ($fstype == 'ntfs' ? 'link' : 'copy');
    */

      copy($sv->INSTALL_DIR . '/php5_3/libeay32.dll', $sv->INSTALL_DIR . '/apache/bin/libeay32.dll');
      copy($sv->INSTALL_DIR . '/php5_3/ssleay32.dll', $sv->INSTALL_DIR . '/apache/bin/ssleay32.dll');
      
      // disable some incompatible extensions on winXP
      if (version_compare(Util::GetOSVersion(), '5.1') <= 0) { // XP of earlier
        $inis = array($sv->INSTALL_DIR . '/php5_3/php.ini', $sv->INSTALL_DIR . '/php5_4/php.ini');
        self::DisablePhpExtension($inis[0], 'pdo_sqlsrv_53_ts');
        self::DisablePhpExtension($inis[0], 'sqlsrv_53_ts');
        self::DisablePhpExtension($inis[1], 'pdo_sqlsrv_54_ts');
        self::DisablePhpExtension($inis[1], 'sqlsrv_54_ts');
      }
    }

    // create files hash
    $hlist = self::CreateFolderHashList($sv->SITE_DIR);
    file_put_contents($sv->SITE_DIR . '/.hashlist', serialize($hlist));
    
    try {
      self::StartApache();
      self::StartMySQL();
      
      if ($sv->UPGRADE) {
        // call mysqld_upgrade
        $stdout = null;
        $mysql_upgrade = null;
        if (Util::IsOSX())
          $mysql_upgrade = './mysql_upgrade';
        else if (Util::IsWin())
          $mysql_upgrade = 'mysql_upgrade.exe';
        Util::ExecC("$mysql_upgrade --protocol=tcp -h127.0.0.1 -P{$sv->MYSQL_PORT} --force", $stdout, $sv->INSTALL_DIR . '/mysql/bin');
        
        // run /upgrade.php
        $u = new DrupalUpgrader();
        $u->upgrade("http://localhost:{$sv->APACHE_PORT}/");
        
        //delete old control panel if exists
        if (file_exists($sv->INSTALL_DIR . '/Acquia Drupal Control Panel.app')) {
          Util::RmDir($sv->INSTALL_DIR . '/Acquia Drupal Control Panel.app');
        }
        if (file_exists($sv->INSTALL_DIR . '/AcquiaDrupalControlPanel')) {
          Util::RmDir($sv->INSTALL_DIR . '/AcquiaDrupalControlPanel');
        }
      }
      else {
        self::CreateDB();
        
        if ($sv->PLATFORM_NAME == 'windows')
          self::SetupXMail();
        
        self::InstallDrupal();
        self::PatchDrupalSettings();
      }
      
    //self::SetDrupalBaseUrl( $drpSettingsPath . '/settings.php' );
    }
    catch (Exception $e) {
      _Log('An error caught. Stopping servers. ');
      // we don't care about exceptions here
      try {
        self::StopMySQL();
      }
      catch (Exception $__e) {
      }
      try {
        self::StopApache();
      }
      catch (Exception $__e) {
      }
      
      throw $e;
    }
    
    self::StopMySQL();
    self::StopApache();
    
    // execute upgrade handlers
    if ($sv->UPGRADE) {
      self::ExecuteUpgradeHandlers();
    }    
    
    if ($sv->PLATFORM_NAME == 'osx') {
      _Log("Prepare PHP extension helper scripts");
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_2');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_3');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_4');      
    }
    
    _Log('Post install script completed successfully');
  }

  private static function PatchDrupalSettings() {
    $sv = SetupVars::get();
    $sfile = $sv->SITE_DIR . '/sites/default/settings.php';
    if ($sv->PLATFORM_NAME == 'windows' && PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION == 2) {
      $stat = stat($sfile);
      chmod($sfile, 0666);
      $text = file_get_contents($sfile);
      $text .= "\n\$conf['drupal_http_request_fails'] = FALSE; // Disable system warning caused by this bug http://bugs.php.net/bug.php?id=50953\n";
      file_put_contents($sfile, $text);
      chmod($sfile, $stat['mode']);
    }
  }

  private static function ExecuteUpgradeHandlers() {
    $sv = SetupVars::get();
    _Log("Run upgrade handlers...");
    $oldCpIniS = $sv->_upgCtx->OldCpIniS();
    if (! empty($oldCpIniS['common']['adVer'])) {
      $oldAdVer = $oldCpIniS['common']['adVer'];
      $oldAdVer = explode('.', $oldAdVer);
      $majVer = $oldAdVer[0];
      $oldAdVer = (int) sprintf('%02d%02d%03d', $oldAdVer[0], $oldAdVer[1], $oldAdVer[2]);
      _Log("Old version detected $oldAdVer");     
      $rc = new ReflectionClass(__CLASS__);
      $methods = $rc->getMethods();
      $handlers = array();
      foreach ($methods as $m) {
        /* @var $m ReflectionMethod */
        $name = explode('_', $m->getName());
        if ($name[0] == 'UpgradeHandler' . $majVer) {
          $handlers[] = (int) $name[1];
        }
      }
      sort($handlers);
      foreach ($handlers as $handlerNum) {
        if ($oldAdVer <= $handlerNum) {
          $handler = 'UpgradeHandler' . $majVer . '_' . $handlerNum;
          _Log("Running $handler");
          self::$handler($sv);
        }
      }
    }
    _Log("Finished upgrade handlers.");
  }

  


  /**
   * @param SetupVars $sv
   */
  private static function UpgradeHandler1_102027($sv) {
    // enable soap in php.ini
    if ($sv->PLATFORM_NAME == 'windows') {
      self::EnablePhpExtension($sv->INSTALL_DIR . '\php\php.ini', 'soap');
    }
  }

  private static function UpgradeHandler1_102030($sv) {
    // fix phpMyAdmin config to allow empty passwords
    $pmaCfg = realpath($sv->INSTALL_DIR . '/phpmyadmin/config.inc.php');
    include ($pmaCfg);
    if (isset($cfg) && empty($cfg['Servers'][0]['AllowNoPassword'])) {
      _Log("Patch phpmyadmin\\config.inc.php to allow empty passwords. (AllowNoPassword)");
      Util::RegexReplaceInFile($pmaCfg, '/^\s*\$cfg\[\'Servers\'\]\[\$i\]\[\'user\'\]\s*=\s*\'drupaluser\'\s*;\s*$/m', "\$0\n\$cfg['Servers'][\$i]['AllowNoPassword']=true;");
    }
  }
  
  private static function UpgradeHandler1_102037($sv) {
    if ($sv->PLATFORM_NAME == 'osx') {
      $newIni = $sv->INSTALL_DIR . '/php5_2/bin/php.ini';
      
      //keep the old 5.2 config
      copy($sv->INSTALL_DIR . '/php/bin/php.ini', $newIni);
      
      // disable xcache, enable apc
      $apcSettings = array (
        'apc.enabled' => '1',
        'apc.shm_segments' => '1',
        'apc.shm_size' => '64M',
        'apc.ttl' => '7200',
        'apc.user_ttl' => '7200',
        'apc.num_files_hint' => '1024',
        'apc.enable_cli' => '1',      
      );
      self::EnablePhpExtension($newIni, 'apc', $apcSettings);
      self::DisablePhpExtension($newIni, 'xcache');
      
      //fix extension path
      self::SetIniOption($newIni, 'extension_dir', '"' . $sv->INSTALL_DIR . '/php5_2/ext"');
    }
    else if ($sv->PLATFORM_NAME == 'windows') {
      //keep the old 5.2 config
      copy($sv->INSTALL_DIR . '/php/php.ini', $sv->INSTALL_DIR . '/php5_2/php.ini');
      //fix extension path
      self::SetIniOption($sv->INSTALL_DIR . '/php5_2/php.ini', 'extension_dir', '"' . $sv->INSTALL_DIR . '\php5_2\ext"');
      
      // enable some new extensions
      $ini = $sv->INSTALL_DIR . '/php5_2/php.ini';
      self::EnablePhpExtension($ini, 'gettext');
      self::EnablePhpExtension($ini, 'mcrypt');
      self::EnablePhpExtension($ini, 'tidy');
      self::EnablePhpExtension($ini, 'sockets');
      $ini = $sv->INSTALL_DIR . '/php5_3/php.ini';
      self::EnablePhpExtension($ini, 'gettext');
      self::EnablePhpExtension($ini, 'tidy');
      self::EnablePhpExtension($ini, 'sockets');
    }
    
    rename($sv->INSTALL_DIR . '/php', $sv->INSTALL_DIR . '/php.old');
    
    // change apache config - add new php settings
    $conf = $sv->INSTALL_DIR . '/apache/conf/httpd.conf';
    Util::RegexReplaceInFile($conf, '/^\s*LoadModule\s+php5_module\s+.*$/m', '');
    
    if ($sv->PLATFORM_NAME == 'osx') {
      $phpBlock = <<<MYDATA
<IfDefine php5_2>
  LoadModule php5_module modules/libphp5_2.so
  PHPINIDir "{$sv->INSTALL_DIR}/php5_2/bin"
</IfDefine>
<IfDefine php5_3>
  LoadModule php5_module modules/libphp5_3.so
  PHPINIDir "{$sv->INSTALL_DIR}/php5_3/bin"
</IfDefine>
MYDATA;
    }
    else if ($sv->PLATFORM_NAME == 'windows') {
      $phpBlock = <<<MYDATA
<IfDefine php5_2>
  LoadModule php5_module "{$sv->INSTALL_DIR}\php5_2\php5apache2_2.dll"
  PHPINIDir "{$sv->INSTALL_DIR}\php5_2"
</IfDefine>
<IfDefine php5_3>
  LoadModule php5_module "{$sv->INSTALL_DIR}\php5_3\php5apache2_2.dll"
  PHPINIDir "{$sv->INSTALL_DIR}\php5_3"
</IfDefine>  
MYDATA;
    }
    Util::RegexReplaceInFile($conf, '/^\s*PHPINIDir\s+.*$/m', $phpBlock);
    
    //remove some unused apache files
    if ($sv->PLATFORM_NAME == 'osx') {
      if (file_exists($sv->INSTALL_DIR . '/apache/modules/libphp5.so')) {
        unlink($sv->INSTALL_DIR . '/apache/modules/libphp5.so');
      }
    }
    else if ($sv->PLATFORM_NAME == 'windows') {
      foreach (array('libmcrypt.dll', 'libmysql.dll', 'php5apache2_2.dll', 'php5ts.dll') as $f) {
        $f = $sv->INSTALL_DIR . '/apache/bin/' . $f;              
        if (file_exists($f)) {
          _Log("Delete $f");
          unlink($f);
        }
      }
    }
  }
  private static function UpgradeHandler7_704004($sv) { return self::UpgradeHandler1_102037($sv); }

  //
  // AD6: Applies to 1.2.39 and earlier
  // AD7: Applies to 7.9.9 and earlier
  //
  private static function UpgradeHandler1_102039($sv) {
    $httpdConf = file_get_contents($sv->INSTALL_DIR . '/apache/conf/httpd.conf');
    $vhostsConf = file_get_contents($sv->INSTALL_DIR . '/apache/conf/vhosts.conf');
    
    $phpBlock = <<<MYDATA
<IfDefine php_fcgi>
  LoadModule fcgid_module modules/mod_fcgid.so
  AddHandler fcgid-script .php
</IfDefine>

MYDATA;

    if ($sv->PLATFORM_NAME == 'osx') {
      $phpBlock .= <<<MYDATA
<IfDefine !php_fcgi>
  <IfDefine php5_2>
    LoadModule php5_module modules/libphp5_2.so
    PHPINIDir "{$sv->INSTALL_DIR}/php5_2/bin"
  </IfDefine>
  <IfDefine php5_3>
    LoadModule php5_module modules/libphp5_3.so
    PHPINIDir "{$sv->INSTALL_DIR}/php5_3/bin"
  </IfDefine>
</IfDefine>
MYDATA;

      // enable some new extensions
      $ini = $sv->INSTALL_DIR . '/php5_2/bin/php.ini';
      self::EnablePhpExtension($ini, 'memcache');
      $ini = $sv->INSTALL_DIR . '/php5_3/bin/php.ini';
      self::EnablePhpExtension($ini, 'memcache');
    }
    else if ($sv->PLATFORM_NAME == 'windows') {
      $phpBlock .= <<<MYDATA
<IfDefine !php_fcgi>
  <IfDefine php5_2>
    LoadModule php5_module "{$sv->INSTALL_DIR}\php5_2\php5apache2_2.dll"
    PHPINIDir "{$sv->INSTALL_DIR}\php5_2"
  </IfDefine>
  <IfDefine php5_3>
    LoadModule php5_module "{$sv->INSTALL_DIR}\php5_3\php5apache2_2.dll"
    PHPINIDir "{$sv->INSTALL_DIR}\php5_3"
  </IfDefine>  
</IfDefine>
MYDATA;

      // enable some new extensions
      $ini = $sv->INSTALL_DIR . '/php5_2/php.ini';
      self::EnablePhpExtension($ini, 'dba');
      self::EnablePhpExtension($ini, 'ldap');
      self::EnablePhpExtension($ini, 'mime_magic');
      self::EnablePhpExtension($ini, 'pdo_sqlite');
      self::EnablePhpExtension($ini, 'shmop');
      self::EnablePhpExtension($ini, 'sqlite');
      self::EnablePhpExtension($ini, 'xsl');
      self::EnablePhpExtension($ini, 'memcache');
      self::EnablePhpExtension($ini, 'pdo_sqlsrv_52_ts_vc6');
      self::EnablePhpExtension($ini, 'sqlsrv_52_ts_vc6');
      $ini = $sv->INSTALL_DIR . '/php5_3/php.ini';
      self::EnablePhpExtension($ini, 'fileinfo');
      self::EnablePhpExtension($ini, 'ldap');
      self::EnablePhpExtension($ini, 'pdo_sqlite');
      self::EnablePhpExtension($ini, 'shmop');
      self::EnablePhpExtension($ini, 'sqlite');
      self::EnablePhpExtension($ini, 'sqlite3');
      self::EnablePhpExtension($ini, 'xsl');
      self::EnablePhpExtension($ini, 'memcache');
      self::EnablePhpExtension($ini, 'pdo_sqlsrv_53_ts_vc9');
      self::EnablePhpExtension($ini, 'sqlsrv_53_ts_vc9');
    }

    $httpdConf = preg_replace('/<IfDefine\s+php5_2>.*<\/IfDefine>/sU', '', $httpdConf);
    $httpdConf = preg_replace('/<IfDefine\s+php5_3>.*<\/IfDefine>/sU', $phpBlock, $httpdConf);
    $httpdConf = preg_replace('/(<Directory\s+"[^\n]+phpmyadmin">.*)Options(.*<\/Directory>)/sU', '$1Options ExecCGI$2', $httpdConf);
    $httpdConf = preg_replace('/<VirtualHost\s+\*>.*<\/VirtualHost>/sU', '', $httpdConf);

    $block = <<<MYDATA
<VirtualHost *>
  ServerName localhost
  DocumentRoot "{$sv->SITE_DIR}"
</VirtualHost>
MYDATA;

    $vhostsConf = $block . "\n\n" . $vhostsConf;
    file_put_contents($sv->INSTALL_DIR . '/apache/conf/httpd.conf', $httpdConf);
    file_put_contents($sv->INSTALL_DIR . '/apache/conf/vhosts.conf', $vhostsConf);
  }
  private static function UpgradeHandler7_709009($sv) { return self::UpgradeHandler1_102039($sv); }

  //
  // AD6: Applies to 1.2.46 and earlier
  // AD7: Applies to 7.16.16 and earlier
  //
  private static function UpgradeHandler1_102046($sv) {
    $httpdConf = $sv->INSTALL_DIR . '/apache/conf/httpd.conf';
    
    $text = file_get_contents($httpdConf);
    if (strpos($text, '<IfDefine php5_4>') !== FALSE)
      return;
    
    if ($sv->PLATFORM_NAME == 'osx') {  
      //
      // OSX
      //
      $conf54=<<<CONF54

  <IfDefine php5_4>
    LoadModule php5_module modules/libphp5_4.so
    PHPINIDir "{$sv->INSTALL_DIR}/php5_4/bin"
  </IfDefine>
CONF54;
    }
    else if ($sv->PLATFORM_NAME == 'windows') {
      //
      // Windows
      //
      $conf54=<<<CONF54

  <IfDefine php5_4>
    LoadModule php5_module "{$sv->INSTALL_DIR}\php5_4\php5apache2_2.dll"
    PHPINIDir "{$sv->INSTALL_DIR}\php5_4"
  </IfDefine>
CONF54;
    }

    Util::RegexReplaceInFile(
      $httpdConf,
      '/^(.*<IfDefine php5_3>.*<\/IfDefine>)(.*)$/sU',
      '$1' . $conf54 . '$2');
  }
  private static function UpgradeHandler7_716016($sv) { return self::UpgradeHandler1_102046($sv); }



  public static function EnablePhpExtension($iniPath, $extName, $params) {
    $sv = SetupVars::get();
    if ($params === NULL) {
      $params = array();
    }
    _Log("Enable $extName extension in $iniPath");
    $text = file_get_contents($iniPath);
    if (!$text) {
      acqThrow("Unable to read $iniPath");
    }
    $text = self::_EnablePhpExtension($text, $extName, $params);
    if (!file_put_contents($iniPath, $text)) {
      acqThrow("Unable to write $iniPath");
    }
  }

  
  private static function DisablePhpExtension($iniPath, $extName) {
    $sv = SetupVars::get();
    $os = $sv->PLATFORM_NAME;
    _Log("Disable $extName extension in $iniPath");
    // define OS specific paramters
    if ($os == 'windows') {
      $extName = "php_$extName.dll";
    }
    else {
      $extName = "$extName.so";
    }    
    Util::RegexReplaceInFile($iniPath, sprintf('/^\s*extension\s*=\s*%s\s*$/m', preg_quote($extName)), ';$0');
  }
  
  
  private static function _EnablePhpExtension($iniTxt, $extName, $params) {
    $sv = SetupVars::get();
    $os = $sv->PLATFORM_NAME;
    
    // define OS specific paramters
    if ($os == 'windows') {
      $prefix = 'php_';
      $fileExt = 'dll';
    }
    else {
      $prefix = '';
      $fileExt = 'so';
    }
    $extFileName = "$prefix$extName.$fileExt";
    
    $lines = explode("\n", $iniTxt);
    $extFound = false;
    $lastSettingLine = - 1;
    $lineNum = 0;
    $ptrn = sprintf('/^[\s;]*extension\s*=\s*%s.*$/', $extFileName);
    foreach ($lines as &$line) {
      
      if (preg_match($ptrn, $line)) {
        if (!$extFound) {
          $line = "extension=$extFileName";
          $extFound = true;
        }
        else if(preg_match(sprintf('/^\s*extension\s*=\s*%s.*$/', preg_quote($extFileName)), $line)) {
          $line = ';' . $line;
        }
      }
      
      // If extension setting is already defined - redefine it
      foreach ($params as $pname => $pval) {
        if (strpos($line, $pname) !== false) {
          $sPtrn = sprintf('/^[\s;]*%s\s*=.*$/', preg_quote($pname));
          if (preg_match($sPtrn, $line)) {
            $line = "$pname=$pval";
            unset($params[$pname]);
            $lastSettingLine = $lineNum;
          }
        }
      }
      
      $lineNum ++;      
    }
    
    if (! $extFound) {
      $lines[] = "extension=$prefix$extName.$fileExt";
    }
    
    // Add settings that have not been redefined
    $newSettings = array();
    foreach ($params as $pname => $pval) {
      $newSettings[] = "$pname=$pval";
    }
    
    if ($lastSettingLine != - 1) {
      array_splice($newLines, $lastSettingLine + 1, 0, $newSettings);
    }
    else {
      $lines = array_merge($lines, $newSettings);
    }
    
    return implode("\n", $lines);
  }
  
  
  
  public static function SetIniOption($file, $name, $val)
  {
    _Log("Set $name=$val in $file");
    $text = '';
    if (($text = file_get_contents($file)) === false) {
      acqThrow('Cannot read:' . $file);
    }
    $lines = explode("\n", $text);
    $found = false;
    $ptrn = sprintf('/^[\s;]*%s\s*=.*$/', preg_quote($name));
    foreach ($lines as &$line) {
      if (preg_match($ptrn, $line)) {
        if (!$found) {
          $line = "$name=$val";
          $found = true;
        } 
        else if(preg_match(sprintf('/^\s*%s\s*=.*$/', preg_quote($name)), $line)) {          
          $line = ';' . $line;
        }
      }
    }
    if (!$found) {
      $lines[] = "$name=$val";
    }
    $text = implode("\n", $lines);
    if (!file_put_contents($file, $text)) {
      acqThrow('Cannot write:' . $file);
    }    
  }


  private static function SubstituteConfigVars() {
    $sv = SetupVars::get();
    
    self::SubstituteFile($sv->INSTALL_DIR . '/MasterLicense.txt');
    
    self::SubstituteFile($sv->INSTALL_DIR . '/apache/conf/httpd.conf');
    self::SubstituteFile($sv->INSTALL_DIR . '/apache/conf/vhosts.conf');
    if (! file_exists($sv->INSTALL_DIR . '/apache/conf/vhosts.conf'))
      file_put_contents($sv->INSTALL_DIR . '/apache/conf/vhosts.conf', "");
    self::SubstituteFile($sv->INSTALL_DIR . '/mysql/my.cnf');
    
    self::SubstituteFile($sv->INSTALL_DIR . '/phpmyadmin/config.inc.php');
    self::SubstituteFile($sv->INSTALL_DIR . '/phpmyadmin/config.inc.php', array('@@BLOWFISH_SECRET@@' => md5(microtime())));
    
    $drpSettingsPath = $sv->SITE_DIR . '/sites/default';
    
    if (! file_exists($drpSettingsPath . '/settings.php') && file_exists($drpSettingsPath . '/default.settings.php')) {
      copy($drpSettingsPath . '/default.settings.php', $drpSettingsPath . '/settings.php');
    }
    
    @mkdir($sv->SITE_DIR . '/sites/default/files');
    
    //create phpinfo file
    file_put_contents($sv->SITE_DIR . '/phpinfo.php', $GLOBALS['DATA_PHPINFO_PHP_CODE']);
    
    if ($sv->PLATFORM_NAME == 'windows') {
      self::SubstituteFile($sv->INSTALL_DIR . '/AcquiaDevDesktopControlPanel/static.ini');
      self::SubstituteFile($sv->INSTALL_DIR . '/AcquiaDevDesktopControlPanel/dynamic.ini'); //

      $repl = array('@@TEMP_DIR@@' => rtrim(sys_get_temp_dir(), '\\'), '@@TIMEZONE@@' => date_default_timezone_get());
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_2/php.ini', $repl);
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_3/php.ini', $repl);
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_4/php.ini', $repl);
      
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysql.cmd');
      
      //windows mysql doesn't like \'s in ini paths.
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/my.cnf', array('\\' => '/'));
    
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      self::SubstituteFile($sv->INSTALL_DIR . '/apache/bin/httpd');
      self::SubstituteFile($sv->INSTALL_DIR . '/apache/bin/apachectl');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysqladmin');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysqld');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysql');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysqldump');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysql_upgrade');
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/bin/mysqlcheck');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_2/bin/php-cgi');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_2/bin/php.ini');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_3/bin/php-cgi');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_3/bin/php.ini');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_4/bin/php-cgi');
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_4/bin/php.ini');
      
      
      $repl = array('@@TEMP_DIR@@' => '/tmp', '@@TIMEZONE@@' => date_default_timezone_get());
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_2/bin/php.ini', $repl);
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_3/bin/php.ini', $repl);
      self::SubstituteFile($sv->INSTALL_DIR . '/php5_4/bin/php.ini', $repl);
      
      self::SubstituteFile($sv->INSTALL_DIR . '/common/envvars');
      self::SubstituteFile($sv->INSTALL_DIR . '/drush/drush');
      
      self::SubstituteFile($sv->INSTALL_DIR . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/static.ini');
      self::SubstituteFile($sv->INSTALL_DIR . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/dynamic.ini');
      
      chmod($sv->SITE_DIR . '/sites/default/settings.php', 0444);
      chmod($sv->INSTALL_DIR . '/mysql/data/mysql', 0775);
    }
    else {
      acqThrow('Unsupported platform:' . $sv->PLATFORM_NAME);
    }
  
  }

  private static function UpgradeCreateContext() {
    $sv = SetupVars::get();
    $c = new UpgradeContext();
    $c->instBackupDir = self::GetRollbackBackupPath($sv->INSTALL_DIR);
    $c->siteBackupDir = self::GetRollbackBackupPath($sv->SITE_DIR);
    
    if (Util::IsOSX()) {
      if (file_exists($sv->INSTALL_DIR . '/Acquia Drupal Control Panel.app')) {
        $c->oldCpIniPathD = $sv->INSTALL_DIR . '/Acquia Drupal Control Panel.app/Contents/MacOS/dynamic.ini';
        $c->oldCpIniPathS = $sv->INSTALL_DIR . '/Acquia Drupal Control Panel.app/Contents/MacOS/static.ini';
      }
      else if (file_exists($c->instBackupDir . '/Acquia Dev Desktop Control Panel.app')) {
        $c->oldCpIniPathD = $c->instBackupDir . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/dynamic.ini';
        $c->oldCpIniPathS = $c->instBackupDir . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/static.ini';
      }
      else {
        _Log("sv:%s \nc:%s\n", $sv, $c);
        acqThrow('Control panel not found');
      }
      $c->newCpIniPathD = $sv->INSTALL_DIR . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/dynamic.ini';
      $c->newCpIniPathS = $sv->INSTALL_DIR . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/static.ini';
    }
    else if (Util::IsWin()) {
      if (file_exists($sv->INSTALL_DIR . '/AcquiaDrupalControlPanel')) {
        $c->oldCpIniPathD = $sv->INSTALL_DIR . '/AcquiaDrupalControlPanel/dynamic.ini';
        $c->oldCpIniPathS = $sv->INSTALL_DIR . '/AcquiaDrupalControlPanel/static.ini';
      }
      else if (file_exists($c->instBackupDir . '/AcquiaDevDesktopControlPanel')) {
        $c->oldCpIniPathD = $c->instBackupDir . '/AcquiaDevDesktopControlPanel/dynamic.ini';
        $c->oldCpIniPathS = $c->instBackupDir . '/AcquiaDevDesktopControlPanel/static.ini';
      }
      else {
        _Log("sv:%s \nc:%s\n", $sv, $c);
        acqThrow(' Control panel not found');
      }
      $c->newCpIniPathD = $sv->INSTALL_DIR . '/AcquiaDevDesktopControlPanel/dynamic.ini';
      $c->newCpIniPathS = $sv->INSTALL_DIR . '/AcquiaDevDesktopControlPanel/static.ini';
    }
    else {
      acqThrow('OS?');
    }
    
    $oldCpSIni = $c->OldCpIniS();
    
    // detect if we are upgrading an old version
    $phpMyAdDir = realpath(dirname(dirname($oldCpSIni['phpMyAdmin']['configPath'])));
    $installDir = realpath($oldCpSIni['common']['installDir']);
    $siteDir = realpath($oldCpSIni['common']['siteDir']);
    if ($phpMyAdDir == $siteDir) {
      $c->upgradeOldVersion = true;
    }
    else if ($phpMyAdDir == $installDir) {
      $c->upgradeOldVersion = false;
    }
    else {
      acqThrow("Unable to detect existing install version");
    }
    _Log("upgradeOldVersion:" . (int) $c->upgradeOldVersion);
    
    return $c;
  }

  private static function UpgradeDetectOldAdmEmail() {
    // we take ServerAdmin value from httpd.conf as admin email
    $sv = SetupVars::get();
    
    $oldCpIniS = $sv->_upgCtx->OldCpIniS();
    $httpdConf = @file_get_contents($oldCpIniS['common']['installDir'] . '/apache/conf/httpd.conf');
    if (! $httpdConf)
      acqThrow('cannot read old httpd.conf');
    $matches = null;
    preg_match('/^\s*ServerAdmin\s+([^\s]+)\s*$/m', $httpdConf, $matches);
    
    return $matches[1];
  }

  private static function UpgradeMergeInstallDir() {
    _Log("Merging install folder");
    $sv = SetupVars::get();
    /* @var $uctx UpgradeContext */
    $uctx = $sv->_upgCtx;
    
    if (! file_exists($sv->INSTALL_DIR . '/mysql/data.bak'))
      acqThrow('data.bak not found');
    
    Util::RmDir($sv->INSTALL_DIR . '/mysql/data');
    if (! @rename($sv->INSTALL_DIR . '/mysql/data.bak', $sv->INSTALL_DIR . '/mysql/data'))
      acqThrow('rename failed');
      
    // check rollback dir        
    if (! file_exists($uctx->instBackupDir))
      acqThrow("install dir backup not found - '{$uctx->instBackupDir}'");
      
    // handle CP configs

    //keep registered sites metainfo && CP settings 
    copy($uctx->oldCpIniPathD, $uctx->newCpIniPathD);
    

    if ($sv->PLATFORM_NAME == 'windows') {
      if (file_exists($uctx->instBackupDir . '/php5_2/php.ini')) {
        copy($uctx->instBackupDir . '/php5_2/php.ini', $sv->INSTALL_DIR . '/php5_2/php.ini');
        copy($uctx->instBackupDir . '/php5_3/php.ini', $sv->INSTALL_DIR . '/php5_3/php.ini');
        copy($uctx->instBackupDir . '/php5_4/php.ini', $sv->INSTALL_DIR . '/php5_4/php.ini');
      }
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      if (file_exists($uctx->instBackupDir . '/php5_2/bin/php.ini')) {
        copy($uctx->instBackupDir . '/php5_2/bin/php.ini', $sv->INSTALL_DIR . '/php5_2/bin/php.ini');
        copy($uctx->instBackupDir . '/php5_3/bin/php.ini', $sv->INSTALL_DIR . '/php5_3/bin/php.ini');
        copy($uctx->instBackupDir . '/php5_4/bin/php.ini', $sv->INSTALL_DIR . '/php5_4/bin/php.ini');

      }
    }
    
    if ($uctx->upgradeOldVersion) {
      self::UpgradeConvertVhostsConf();
      self::UpgradeConvertHttpdConf();
    }
    else {
      copy($uctx->instBackupDir . '/apache/conf/vhosts.conf', $sv->INSTALL_DIR . '/apache/conf/vhosts.conf');
      copy($uctx->instBackupDir . '/apache/conf/httpd.conf', $sv->INSTALL_DIR . '/apache/conf/httpd.conf');
      copy($uctx->instBackupDir . '/phpmyadmin/config.inc.php', $sv->INSTALL_DIR . '/phpmyadmin/config.inc.php');
    }
    
    //copy( $insDirBackup.'/apache/conf/vhosts.conf', $sv->INSTALL_DIR.'/apache/conf/vhosts.conf' ); // vhosts is cerated dynamicallly
    copy($uctx->instBackupDir . '/mysql/my.cnf', $sv->INSTALL_DIR . '/mysql/my.cnf');
  }

  private static function UpgradeConvertVhostsConf() {
    $sv = SetupVars::get();
    $ini = $sv->_upgCtx->NewCpIniD();
    $vhosts = @file_get_contents($sv->INSTALL_DIR . '/apache/conf/vhosts.conf');
    if ($vhosts === false)
      acqThrow('cannot load vhosts.conf');
    
    for ($i = 1; isset($ini["sites/m_sites/$i"]); $i ++) {
      $cb = $ini["sites/m_sites/$i"]['codebasePath'];
      $host = $ini["sites/m_sites/$i"]['host'];
      if (! $cb) {
        $c = 0;
        $vhosts = preg_replace('/<VirtualHost\s+\*>[^<]*ServerName\s+' . preg_quote($host) . '[^<]*<\/VirtualHost>/', "<VirtualHost *>\n  ServerName $host\n  DocumentRoot \"{$sv->SITE_DIR}\"\n</VirtualHost>\n", $vhosts, - 1, $c);
      }
    }
    if (file_put_contents($sv->INSTALL_DIR . '/apache/conf/vhosts.conf', $vhosts) === false)
      acqThrow('cannot save vhosts.conf');
  }

  private static function UpgradeConvertHttpdConf() {
    $sv = SetupVars::get();
    $httpdConf = @file_get_contents($sv->INSTALL_DIR . '/apache/conf/httpd.conf');
    if ($httpdConf === false)
      acqThrow('cannot load httpd.conf');
    
    $httpdConf = preg_replace('/<VirtualHost\s+\*>[^<]*ServerName\s+localhost[^<]*<\/VirtualHost>/', '', $httpdConf);
    $httpdConf = preg_replace('/^\s*Listen\s+\d+\s*$/mi', "Listen {$sv->APACHE_PORT}", $httpdConf);
    $httpdConf = preg_replace('/^\s*ServerName\s+[\d.\w:]+\s*$/mi', "ServerName 127.0.0.1:{$sv->APACHE_PORT}", $httpdConf);
    
    $matches = array();
    if (! preg_match('/^\s*DocumentRoot\s+(.*)\s*$/mi', $httpdConf, $matches))
      acqThrow('httpd.conf: DocumentRoot not found');
    $oldDocRoot = trim($matches[1]);
    
    $httpdConf = str_replace($oldDocRoot, "\"{$sv->SITE_DIR}\"", $httpdConf);
    
    //echo $httpdConf;
    if (! file_put_contents('httpd.conf', $httpdConf))
      acqThrow('cannot save httpd.conf');
  }

  private static function UpgradeMergeSiteDir() {
    _Log("Merging site folder...");
    $sv = SetupVars::get();
    /* @var $uctx UpgradeContext */
    $uctx = $sv->_upgCtx;
    
    $replacedFiles = array();
    if ($uctx->upgradeOldVersion) {
      $oldCpIniS = $uctx->OldCpIniS();
      $oldSiteDir = $oldCpIniS['common']['siteDir'] . '/acquia-drupal';
      if (! file_exists($oldSiteDir))
        acqThrow("Old site directory not found - '$oldSiteDir'");
        
      // copy default site settings
      copy($oldSiteDir . '/sites/default/settings.php', $sv->SITE_DIR . '/sites/default/settings.php');
      
      self::_MergeOldNewCodebase($oldSiteDir, $sv->SITE_DIR, $replacedFiles);
      
      // move old file to #oldsite dir
      if (realpath($oldCpIniS['common']['siteDir']) == realpath($sv->SITE_DIR)) {
        _Log("Moving old site to the #oldsite folder");
        if (! mkdir($sv->SITE_DIR . '/#oldsite')) {
          _Log("Cannot create #oldsite folder");
        }
        else {
          if (file_exists($sv->SITE_DIR . '/acquia-drupal'))
            rename($sv->SITE_DIR . '/acquia-drupal', $sv->SITE_DIR . '/#oldsite/acquia-drupal');
          if (file_exists($sv->SITE_DIR . '/phpmyadmin'))
            rename($sv->SITE_DIR . '/phpmyadmin', $sv->SITE_DIR . '/#oldsite/phpmyadmin');
        }
      }
    }
    else {
      $exclude = array('sites/default/settings.php');
      
      $siteDirBackup = $uctx->siteBackupDir;
      if (! file_exists($siteDirBackup))
        acqThrow('site dir rollbackBackup not found');
      
      if (! file_exists($sv->SITE_DIR . '/.hashlist'))
        acqThrow('.hashlist not found');
      
      $hashlist = @file_get_contents($sv->SITE_DIR . '/.hashlist');
      $hashlist = unserialize($hashlist);
      
      if (! is_array($hashlist))
        acqThrow('hashlist is invalid');
      
      copy($siteDirBackup . '/sites/default/settings.php', $sv->SITE_DIR . '/sites/default/settings.php');
      
      foreach ($hashlist as $fpath => $fhash) {
        $oldfile = $siteDirBackup . '/' . $fpath;
        if (! in_array($fpath, $exclude) && file_exists($oldfile) && md5_file($oldfile) != $fhash) {
          // file has been modified
          $newfile = $sv->SITE_DIR . '/' . $fpath . '.bak';
          $replacedFiles[] = $newfile;
          copy($oldfile, $newfile);
        }
      }
    }
    return $replacedFiles;
  }

  public static function UpgradeMergeOldNewSiteDir() {
    $sv = SetupVars::get();
    
    $diff = array();
    
    return $diff;
  }

  private static function _MergeOldNewCodebase($oldDir, $newDir, &$diff) {
    $dlist = glob($oldDir . '/*', GLOB_NOSORT);
    $dlist = array_merge($dlist, glob($oldDir . '/.*', GLOB_NOSORT));
    foreach ($dlist as $oldFile) {
      $oldFileName = basename($oldFile);
      $newFile = $newDir . '/' . $oldFileName;
      if (is_dir($oldFile)) {
        if ($oldFileName != '.' && $oldFileName != '..') {
          if (! file_exists($newFile)) {
            $diff[] = $newFile;
            if (! mkdir($newFile))
              acqThrow('cannot create directory - ' . $newFile);
          }
          self::_MergeOldNewCodebase($oldFile, $newFile, $diff);
        }
      }
      else {
        if (! file_exists($newFile)) {
          $diff[] = $newFile;
          copy($oldFile, $newFile);
        }
      }
    }
  }

  private static function GetRollbackBackupPath($dir) {
    $sv = SetupVars::get();
    $i = '';
    $rbPath = FALSE;
    do {
      $rbPath = realpath($sv->INSTALL_DIR . "/rollbackBackupDirectory$i/" . str_replace(':', '', $dir));
      if ($i === '') {
        $i = 0;
      }
      $i++;
      $rbPathNext = realpath($sv->INSTALL_DIR . "/rollbackBackupDirectory$i/" . str_replace(':', '', $dir));

    } while($rbPathNext !== FALSE);
    
    if ($rbPath === FALSE) {
       acqThrow("rollbackBackupDirectory not found");
    }
    
    _Log("rollbackBackupDirectory found: $rbPath");
    
    return $rbPath;
  }

  private static function SetDrupalBaseUrl($settingsFile, $host = null, $port = null) {
    $sv = SetupVars::get();
    if ($host === null) {
      $host = 'localhost';
    }
    if ($port === null) {
      $port = $sv->APACHE_PORT;
    }
    
    Util::RegexReplaceInFile($settingsFile, '/^[^*]*\$base_url.*$/m', sprintf('$base_url=\'http://%s:%d\';', $host, $port));
  
  }

  public static function FinishSiteInstall($args) {
    //print_r($args); return;
    _Log('Finish site install');
    
    DrupalCli::Setup($_SERVER);
    $installer = new DrupalInstaller();
    
    $installer->profile = base64_decode(@$args['profile']);
    $installer->site_name = base64_decode(@$args['site_name']);
    $installer->username = base64_decode(@$args['username']);
    $installer->password = base64_decode(@$args['password']);
    $installer->email = base64_decode(@$args['email']);
    
    $installer->install(base64_decode(@$args['baseurl']), false);
  }

  private static function SetupXMail() {
    $sv = SetupVars::get();
    if (file_exists($sv->INSTALL_DIR . '\xmail')) {
      $stdout = null;
      Util::ExecC('"' . $sv->INSTALL_DIR . '\xmail\XMail.exe" --install-auto', $stdout);
      Util::ExecC('reg add HKLM\Software\GNU\XMail /f /v MAIL_ROOT /t REG_MULTI_SZ /d "' . $sv->INSTALL_DIR . '\xmail\MailRoot"', $stdout);
      Util::ExecC('reg add HKLM\Software\GNU\XMail /f /v MAIL_CMD_LINE /t REG_MULTI_SZ /d "-P- -B- -X- -Y- -F- -Mx 3"', $stdout);
      Util::ExecC('net start XMail', $stdout);
    }
  }

  private static function SetupUnixPhpize($phpFolder) {
    $sv = SetupVars::get();
    // find php version
    $phpBin = escapeshellcmd($phpFolder . '/bin/php');
    
    $stderr = $stdout = null;
    Util::Exec("$phpBin -v", $stdout, $stderr);
    
    $matches = null;
    preg_match('/^PHP\s+([\d\.]+)/', $stdout, $matches);
    $phpVer = $matches[1];
    
    // find php version id
    $phpVerId = vsprintf('%d%02d%02d', explode('.', $phpVer));
    
    $instDir = $sv->INSTALL_DIR . '/common';
    // replace vars in php-config
    $repl = array('@prefix@' => $phpFolder, '@SED@' => '/usr/bin/sed', '@exec_prefix@' => '${prefix}', '@PHP_VERSION@' => $phpVer, '@PHP_VERSION_ID@' => $phpVerId, '@includedir@' => '${prefix}/include',
                  '@PHP_LDFLAGS@' => " -L {$sv->INSTALL_DIR}/common/lib", 
                  '@EXTRA_LIBS@' => ' -lz -lmysqlclient -liconv -liconv -lpng -lz -ljpeg -lssl -lcrypto -lcurl -lbz2 -lz -lssl -lcrypto -lm  -lxml2 -lz -licucore -lm -lcurl -lz -lxml2 -lz -licucore -lm -lmysqlclient -lz -lm -lmysqlclient -lz -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm ', 
                  '@EXTENSION_DIR@' => "$phpFolder/ext", '@program_prefix@' => '', '@program_suffix@' => '', '@EXEEXT@' => '', 
                  '@CONFIGURE_OPTIONS@' => " '--prefix=$phpFolder/php' '--with-gd' '--with-png-dir=$instDir' '--with-curl=$instDir' '--enable-mbstring' '--enable-pcntl' '--enable-ftp' '--with-zlib' '--with-bz2' '--enable-zip' '--with-openssl=$instDir' '--with-mysql=$instDir'  '--with-pdo-mysql=$instDir'", 
                  '@PHP_INSTALLED_SAPIS@' => "", '@bindir@' => '${exec_prefix}/bin');
    _Log("SetupUnixPhpize(): replace in php-config\n" . print_r($repl, true));
    $target = $phpFolder . '/bin/php-config';
    self::SubstituteFile($target . '.in', $repl);
    rename($target . '.in', $target);
    chmod($target, 0777);
    
    $repl = array('@prefix@' => $phpFolder, '@exec_prefix@' => '${prefix}', '@libdir@' => '${exec_prefix}/lib/php', '@includedir@' => '${prefix}/include', '@SED@' => '/usr/bin/sed');
    _Log("SetupUnixPhpize(): replace in phpize\n" . print_r($repl, true));
    $target = $phpFolder . '/bin/phpize';
    self::SubstituteFile($target . '.in', $repl);
    rename($target . '.in', $target);
    chmod($target, 0777);
  }

  private static function SubstituteFile($filename, $pairs = array()) {
    _Log('Making substitutions in "%s"', $filename);
    if (empty($pairs)) {
      $sv = SetupVars::get();
      $rc = new ReflectionClass('SetupVars');
      $props = $rc->getProperties();
      foreach ($props as $prop) {
        /*@var $prop ReflectionProperty*/
        if ($prop->isPublic() && ! $prop->isStatic() && substr($prop->getName(), 0, 1) != '_')
          $pairs['@@' . $prop->getName() . '@@'] = $prop->getValue($sv);
      }
    }
    $realname = realpath($filename);
    $data = file_get_contents($realname);
    if ($data === false)
      acqThrow('Cannot read ' . $filename);
    $data = str_replace(array_keys($pairs), array_values($pairs), $data);
    if (file_put_contents($realname, $data) === false)
      acqThrow('Cannot write ' . $filename);
  }

  private static function UnixPath($path) {
    if (SetupVars::get()->PLATFORM_NAME == 'windows')
      $path = str_replace('\\', '/', $path);
    else if (SetupVars::get()->PLATFORM_NAME == 'osx')
      $path = str_replace(':', '/', $path);
    return rtrim($path, '/');
  }

  private static function StartMySQL() {
    _Log("Starting MySQL");
    $sv = SetupVars::get();
    $cmdline = '';
    
    $tmpdir = sys_get_temp_dir();
    if (substr($tmpdir, - 1) == DIRECTORY_SEPARATOR) {
      $tmpdir = substr($tmpdir, 0, - 1);
    }
    
    $outFile = $tmpdir . DIRECTORY_SEPARATOR . 'damp_setup.out';
    $errFile = $tmpdir . DIRECTORY_SEPARATOR . 'damp_setup.err';
    
    if ($sv->PLATFORM_NAME == 'windows') {
      $cmdline = sprintf('cmd.exe /c start "mysqld" /B mysqld.exe --defaults-file="%s" >"%s" 2>"%s"', realpath($sv->INSTALL_DIR . '/mysql/my.cnf'), $outFile, $errFile);
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      $cmdline = sprintf('sudo -u %s ./mysqld --defaults-file="%s" >"%s" 2>"%s" &', escapeshellarg(Util::GetRealUser()), realpath($sv->INSTALL_DIR . '/mysql/my.cnf'), $outFile, $errFile);
    }
    else {
      acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
    }
    
    $stdout = null;
    Util::ExecC($cmdline, $stdout, realpath($sv->INSTALL_DIR . '/mysql/bin'), null, true);
    self::WaitMySQLStart();
  
  }

  private static function WaitMySQLStart() {
    
    _Log("Waiting for mysql to start");
    $sv = SetupVars::get();
    $timeout = 30;
    $startTime = time();
    $oldTimeout = ini_get('mysql.connect_timeout');
    $hDB = null;
    ini_set('mysql.connect_timeout', 2);
    
    for ($i = 0;; $i ++) {
      $hDB = @mysql_connect('127.0.0.1:' . $sv->MYSQL_PORT, 'root', '');
      if ($hDB !== false) {
        _Log("Wait succeeded");
        mysql_close($hDB);
        break;
      }
      
      if (time() - $startTime > $timeout) {
        ini_set('mysql.connect_timeout', $oldTimeout);
        $outFile = @file_get_contents(realpath(sys_get_temp_dir() . '/damp_setup.out'));
        $errFile = @file_get_contents(realpath(sys_get_temp_dir() . '/damp_setup.err'));
        
        _Log("Wait failed. (i=$i) mysqlerr:%s\nserver stderr:%s\nserver stdout:%s", mysql_error(), $errFile, $outFile);
        
        acqThrow("MySQL start timeout.");
      }
      sleep(1);
    }
    ini_set('mysql.connect_timeout', $oldTimeout);
  }

  public static function StopMySQL() {
    _Log("Stop MySQL");
    $sv = SetupVars::get();
    
    $pid = intval(@file_get_contents($sv->INSTALL_DIR . '/mysql/data/mysql.pid'));
    
    if ($pid > 0) {
      _Log("MySQL pid:$pid");
      
      if ($sv->PLATFORM_NAME == 'windows') {
        $cmdline = sprintf('mysqladmin.exe --defaults-file="%s" shutdown', realpath($sv->INSTALL_DIR . '/mysql/my.cnf'));
      }
      else if ($sv->PLATFORM_NAME == 'osx') {
        $cmdline = sprintf('./mysqladmin --defaults-file="%s" shutdown', realpath($sv->INSTALL_DIR . '/mysql/my.cnf'));
      }
      else {
        acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
      }
      
      $stdout = $stderr = null;
      Util::Exec($cmdline, $stdout, $stderr, realpath($sv->INSTALL_DIR . '/mysql/bin'));
    }
  }

  private static function StartApache() {
    _Log("Starting Apache");
    $sv = SetupVars::get();
    $cmdline = '';
    
    $env = null;
    if ($sv->PLATFORM_NAME == 'windows') {
      $cmdline = sprintf('cmd.exe /c start "apache" /B httpd.exe -D php5_2 -f "%s"', realpath($sv->INSTALL_DIR . '/apache/conf/httpd.conf'));
      // Alter PATH so apache would pick up correct dlls from the PHP home folder
      $env = Util::GetAllEnv();
      foreach ($env as $n => &$v) {
        if (strcasecmp($n, 'path') == 0) {
          $v = $sv->INSTALL_DIR . '\php5_2;' . $v;
          break;
        }
      }
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      $cmdline = sprintf('sudo -u %s ./httpd -D php5_2 -f "%s"', escapeshellarg(Util::GetRealUser()), realpath($sv->INSTALL_DIR . '/apache/conf/httpd.conf'));
    }
    else {
      acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
    }
    
    $stdout = null;
    Util::ExecC($cmdline, $stdout, realpath($sv->INSTALL_DIR . '/apache/bin'), $env, true);
    self::WaitApacheStart();
  }

  public static function StopApache() {
    _Log("Stop Apache");
    $sv = SetupVars::get();
    
    $pid = intval(@file_get_contents($sv->INSTALL_DIR . '/apache/logs/httpd.pid'));
    if ($pid > 0) {
      _Log("Apache pid:$pid");
      
      $cwd = null;
      if ($sv->PLATFORM_NAME == 'windows') {
        $cmdline = "taskkill.exe /F /T /PID $pid";
        $cwd = "{$sv->INSTALL_DIR}\\common";
      }
      else if ($sv->PLATFORM_NAME == 'osx') {
        $cmdline = "kill $pid";
      }
      else {
        acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
      }
      
      $stdout = $stderr = null;
      Util::Exec($cmdline, $stdout, $stderr, $cwd);
      
      if ($sv->PLATFORM_NAME == 'windows')
        @unlink($sv->INSTALL_DIR . '/apache/logs/httpd.pid');
    }
  }

  private static function WaitApacheStart() {
    _Log('Wait for apache to start');
    $sv = SetupVars::get();
    $timeout = 30;
    $startTime = time();
    for ($i = 0;; $i ++) {
      $errno = 0;
      $errstr = '';
      $fs = @fsockopen('127.0.0.1', $sv->APACHE_PORT, $errno, $errstr, 1);
      if ($fs !== FALSE) {
        _Log("Wait succeeded");
        fclose($fs);
        break;
      }
      if (time() - $startTime > $timeout) {
        _Log("Wait failed. (i=$i) err:$errstr ($errno)");
        acqThrow('Apache start timeout');
      }
      sleep(1);
    }
  }

  /*private*/  public static function InstallDrupal() {
    _Log("Running Drupal web installer...");
    $args = array('profile' => 'acquia', 'db_host' => '127.0.0.1', 'db_path' => SetupVars::get()->DB_NAME, 'db_user' => SetupVars::get()->DB_USERNAME, 'db_pass' => SetupVars::get()->DB_PASSWORD, 'db_port' => SetupVars::get()->MYSQL_PORT, 'site_name' => SetupVars::get()->SITE_NAME, 'username' => SetupVars::get()->ADM_USERNAME, 'password' => SetupVars::get()->ADM_PASSWORD, 'email' => SetupVars::get()->ADM_EMAIL, 'baseurl' => 'http://localhost:' . SetupVars::get()->APACHE_PORT . '/' /*. '/acquia-drupal/'*/ );
    install_drupal($args);
  }

  private static function CreateDB() {
    global $DB_DUMP;
    //
    // connect
    //
    $hDB = @mysql_connect('127.0.0.1:' . SetupVars::get()->MYSQL_PORT, 'root', '');
    if ($hDB === false)
      acqThrow('Unable to connect to MySQL');
    //
    // create database
    //
    $dbName = mysql_real_escape_string(SetupVars::get()->DB_NAME, $hDB);
    $dbUser = mysql_real_escape_string(SetupVars::get()->DB_USERNAME, $hDB);
    $dbPass = mysql_real_escape_string(SetupVars::get()->DB_PASSWORD, $hDB);
    self::MySQLQuery("CREATE DATABASE `$dbName`", $hDB);
    self::MySQLQuery("GRANT ALL PRIVILEGES ON *.* TO `$dbUser`@'localhost' IDENTIFIED BY '$dbPass'", $hDB);
    self::MySQLQuery("GRANT GRANT OPTION ON *.* TO `$dbUser`@'localhost'", $hDB);
    self::MySQLQuery("FLUSH PRIVILEGES", $hDB);
    //
    // disconnect
    //
    mysql_close($hDB);
  }

  private static function MySQLQuery($query, $hDB) {
    _Log("Runing SQL query:" . $query);
    $res = mysql_query($query, $hDB);
    if ($res === false) {
      acqThrow("Query failed.\nQuery:" . substr($query, 0, 200) . "\nError:" . mysql_error($hDB));
    }
    return $res;
  }

  private static function _TraverseDir($dir, &$res) {
    foreach (glob($dir) as $file) {
      if (is_dir($file)) {
        self::_TraverseDir("$file/*", $res);
      }
      else {
        $res[] = $file;
      }
    }
  }

  private static function CreateFolderHashList($path) {
    _Log("Creating hashlist for '$path'...");
    $flist = array();
    $hlist = array();
    self::_TraverseDir($path, $flist);
    
    $l = strlen($path);
    foreach ($flist as $f) {
      $relPath = substr($f, $l + 1);
      if (strpos($relPath, '#oldsite') !== 0)
        $hlist[substr($f, $l + 1)] = md5_file($f);
    }
    return $hlist;
  }
  
  private static $hMysqlProc;
  private static $winSvcNames;
}

class Util {

  public static function IsWin() {
    return (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
  }

  public static function IsWin64() {
    $pf = getenv('ProgramFiles(x86)');
    return !empty($pf);
  }

  public static function GetOSVersion()
  {
    $version = FALSE;
    $parts = preg_split('/\s+/', trim(php_uname()));
    if ($parts[0] == 'Windows')
      $version = $parts[3];
    else if ($parts[1] == 'Darwin')
      $version = $parts[2];
    return $version;
  }


  public static function IsOSX() {
    return (strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN');
  }

  public static function GetMetaRefreshUrl($html, $rel = true) {
    $result = null;
    $matches = null;
    $n = preg_match('/<meta\s+http-equiv="Refresh".*URL=(.*)"/i', $html, $matches);
    if ($n > 0) {
      if ($rel) {
        $parts = parse_url($matches[1]);
        $result = $parts['path'];
        if ($parts['query'])
          $result .= '?' . str_replace('&amp;', '&', $parts['query']);
      }
      else {
        $result = $matches[1];
      }
    }
    return $result;
  }

  public static function Exec($cmd, &$stdout, &$stderr, $cwd = null, $env = null, $bypass_shell = false) {
    $stdout = $stderr = null;
    _Log("Exec:" . $cmd . ($cwd ? " [cwd:$cwd]" : ''));
    $tmpNameOut = tempnam(sys_get_temp_dir(), 'out');
    $tmpNameErr = tempnam(sys_get_temp_dir(), 'err');
    
    $descriptorspec = array(0 => array("pipe", "r"), //stdin
      1 => array("file", $tmpNameOut, "w"), //stdout
      2 => array("file", $tmpNameErr, "w")); //stderr
    
    $sv = SetupVars::get();

    $pipes = array();
    $options = array();
    if ($bypass_shell && $sv->PLATFORM_NAME == 'windows') {
      $options['bypass_shell'] = TRUE;
    }
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env, $options);
    if (is_resource($process)) {
      fclose($pipes[0]);
      $res = proc_close($process);
    }
    else {
      acqThrow(sprintf(' Unable to execute command line - %s', $cmd));
    }
    
    $stdout = @file_get_contents($tmpNameOut);
    $stderr = @file_get_contents($tmpNameErr);
    
    @unlink($tmpNameOut);
    @unlink($tmpNameErr);
    
    _Log("Exec result: $res \nSTDOUT:$stdout \nSTDERR:$stderr");
    
    return $res;
  }

  public static function ExecC($cmd, &$stdout, $cwd = null, $env = null, $bypass_shell = false) {
    $stderr = '';
    $r = self::Exec($cmd, $stdout, $stderr, $cwd, $env, $bypass_shell);
    if ($r != 0)
      acqThrow("Command returned " . $r . ". stderr:" . $stderr);
  }


  public static function GetAllEnv()
  {
    $env = array();
    foreach ($_SERVER as $n => $v) {
      if (getenv($n) == $v) {
        $env[$n] = $v;
      }
    }
    return $env;
  }

  /**
   * Parses ini file
   * Return value format: $res[section_name][var_name]=var_value
   *
   * @param string $file
   * @return array
   */
  public static function ParseIniFile($file) {
    $lines = @file($file);
    if ($lines === false)
      acqThrow("Cannot read:" . $file);
    $res = array();
    $curSection = null;
    foreach ($lines as $line) {
      $line = trim($line);
      if (! empty($line)) {
        $matches = null;
        if (preg_match('/^\[(.*)\]$/', $line, $matches) > 0) {
          $res[$matches[1]] = array();
          $curSection = &$res[$matches[1]];
        }
        elseif (preg_match('/^([^=]+)=(.*)$/', $line, $matches) > 0) {
          $curSection[$matches[1]] = $matches[2];
        }
      }
    }
    return $res;
  }

  public static function SaveIniFile($ini, $filePath) {
    $str = "";
    foreach ($ini as $section => $pairs) {
      $str .= "[$section]\n";
      if (is_array($pairs)) {
        foreach ($pairs as $n => $v) {
          $str .= "$n=$v\n";
        }
      }
      $str .= "\n";
    }
    
    file_put_contents($filePath, $str);
  }

  public static function RmDir($dir) {
    foreach (glob($dir, GLOB_NOSORT) as $file) {
      if (is_dir($file)) {
        $bn = basename($file);
        if ($bn != '.' && $bn != '..') {
          self::RmDir("$file/.*");
          self::RmDir("$file/*");
          rmdir($file);
        }
      }
      else {
        if (self::IsWin())
          chmod($file, 0777); // this clears read-only attribute
        unlink($file);
      }
    }
  }

  public static function CopyDir($from, $to) {
    if (! file_exists($to) && @mkdir($to) === false)
      return false;
    
    foreach (glob($from . '/*', GLOB_NOSORT) as $file) {
      if (is_dir($file)) {
        if (! self::CopyDir($file, $to . '/' . basename($file)))
          return false;
      }
      else {
        copy($file, $to . '/' . basename($file));
      }
    }
    return true;
  }

  public static function ParseArgv($argv) {
    $res = array();
    foreach ($argv as $a) {
      list($nm, $val) = explode('=', $a);
      if (substr($nm, 0, 2) == '--' && ! empty($val)) {
        $nm = trim($nm, '-');
        $res[$nm] = $val;
      }
    }
    return $res;
  }

  public static function RegexReplaceInFile($file, $regex, $repl) {
    _Log("RegexReplaceInFile. file:$file, regex:$regex, repl:$repl");
    $text = file_get_contents($file);
    if ($text === false)
      acqThrow(sprintf(' Unable read file - %s', $file));
    $count = 0;
    $text = preg_replace($regex, $repl, $text, - 1, $count);
    _Log("Nummatch:$count");
    if ($count > 0) {
      $readonly = false;
      if (! is_writable($file)) {
        chmod($file, 0666);
        $readonly = true;
      }
      if (! file_put_contents($file, $text))
        acqThrow(sprintf(' Unable write file - %s', $file));
      
      if ($readonly)
        chmod($file, 0444);
    }
    return $count;
  }

  public static function GetRealUser() {
    $userName = getenv('SUDO_USER') ? getenv('SUDO_USER') : getenv('USER');
    return $userName;
  }

  public static function CompareVersions($v1, $v2) {
    $res = 0;
    
    $v1 = explode('.', $v1);
    $v2 = explode('.', $v2);
    $msz = max(array(count($v1), count($v2)));
    $v1 = array_pad($v1, $msz, "0");
    $v2 = array_pad($v2, $msz, "0");
    for ($i = 0; $i < count($v1) && $res == 0; $i ++) {
      $res = ($v1[$i] < $v2[$i] ? - 1 : ($v1[$i] > $v2[$i] ? 1 : 0));
    }
    
    return $res;
  }
}

class OSXUtil {

  public static function GetOSUserList() {
    _Log(__CLASS__ . '::' . __FUNCTION__);
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Users", $stdout);
    return explode("\n", $stdout);
  }

  public static function GetOSGroupList() {
    _Log(__CLASS__ . '::' . __FUNCTION__);
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Groups", $stdout);
    return explode("\n", $stdout);
  }

  public static function GetOSNewGroupPrimaryID() {
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Groups PrimaryGroupID | awk '{print $2}'", $stdout);
    $ids = explode("\n", $stdout);
    sort($ids, SORT_NUMERIC);
    $unqueId = $ids[count($ids) - 1] + 1;
    return $unqueId;
  }

  public static function GetOSNewUserUniqueID() {
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Users UniqueID | awk '{print $2}'", $stdout);
    $ids = explode("\n", $stdout);
    sort($ids, SORT_NUMERIC);
    $unqueId = $ids[count($ids) - 1] + 1;
    return $unqueId;
  }

  /**
   * @return created group PrimaryGroupID
   */
  public static function CreateOSUserGroup($groupName) {
    _Log(__CLASS__ . '::' . __FUNCTION__ . "($groupName)");
    if (self::OSGroupExists($groupName))
      acqThrow(" group already exists - " . $groupName);
    
    $stdout = '';
    $gid = self::GetOSNewGroupPrimaryID();
    Util::ExecC("dscl localhost -create " . escapeshellarg("/Local/Default/Groups/$groupName"), $stdout);
    Util::ExecC(sprintf("dscl localhost -create %s PrimaryGroupID $gid", escapeshellarg("/Local/Default/Groups/$groupName")), $stdout);
    return intval($gid);
  }

  /**
   * @param string $userName
   * @return int - group PrimaryGroupID on success and null on failure
   */
  public static function GetOSUserGroupID($userName) {
    $gid = self::GetDSPropertyVal("/Local/Default/Users/$userName", 'PrimaryGroupID');
    return $gid === null ? null : intval($gid);
  }

  /**
   * @param int $groupID
   * @return string - group name on success and null otherwise
   */
  public static function GetOSGroupNameByID($groupID) {
    $gnames = self::GetDSNodeListByPropertyKeyVal('/Local/Default/Groups', 'PrimaryGroupID', $groupID);
    return empty($gnames) ? null : $gnames[0];
  }

  public static function DeleteOSUserGroup($groupName) {
    if (! self::OSGroupExists($groupName))
      acqThrow(" Group doesn't exist - " . $groupName);
    
    $stdout = null;
    Util::ExecC(sprintf("dscl localhost -delete %s", escapeshellarg("/Local/Default/Groups/$groupName")), $stdout);
  }

  /**
   * @param string $userName
   * @param string $password
   * @param string $group
   * @return created user ID
   */
  public static function CreateOSUser($userName, $password, $group) {
    _Log("Ceate user(name:$userName password:$password, group:$group )");
    $uid = - 1;
    Util::ExecC(sprintf("dscl localhost -create %s", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    $uid = self::GetOSNewUserUniqueID();
    Util::ExecC(sprintf("dscl localhost -create %s UniqueID $uid", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    $gid = self::GetDSPropertyVal("/Local/Default/Groups/$group", 'PrimaryGroupID');
    Util::ExecC(sprintf("dscl localhost -create %s PrimaryGroupID $gid", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    Util::ExecC(sprintf("dscl localhost -create %s UserShell /usr/bin/false", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    Util::ExecC(sprintf("dscl localhost -passwd %s %s", escapeshellarg("/Local/Default/Users/$userName"), escapeshellarg($password)), $stdout);
    
    self::GroupMembershipUserAdd($group, $userName);
    
    return $uid;
  }

  public static function DeleteOSUser($userName) {
    $stdout = '';
    
    if (! self::OSUserExists($userName))
      acqThrow(" User doesn't exist - " . $userName);
      
    // delete the user from primary group
    $gid = self::GetDSPropertyVal("/Local/Default/Users/$userName", 'PrimaryGroupID');
    if ($gid !== null) {
      
      $gname = self::GetOSGroupNameByID($gid);
      if ($gname) {
        self::GroupMembershipUserRemove($gname, $userName);
      }
    }
    
    Util::ExecC(sprintf("dscl localhost -delete %s", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
  }

  private static function GetGroupMembership($groupName) {
    $res = null;
    $users = self::GetDSPropertyVal("/Local/Default/Groups/$groupName", 'GroupMembership');
    return empty($users) ? array() : explode(' ', $users);
  }

  private static function GroupMembershipUserAdd($group, $user) {
    $groupUsers = self::GetGroupMembership($group);
    if (! in_array($user, $groupUsers))
      Util::ExecC(sprintf("dscl localhost -append %s GroupMembership %s", escapeshellarg("/Local/Default/Groups/$group"), escapeshellarg($user)), $stdout);
  }

  public static function GroupMembershipUserRemove($group, $user) {
    $groupUsers = self::GetGroupMembership($group);
    if (in_array($user, $groupUsers))
      Util::ExecC(sprintf("dscl localhost -delete %s GroupMembership %s", escapeshellarg("/Local/Default/Groups/$group"), escapeshellarg($user)), $stdout);
  }

  private static function PathExists($path) {
    $stdout = $stderr = '';
    $r = Util::Exec(sprintf("dscl localhost -read %s", escapeshellarg($path)), $stdout, $stderr);
    return $r == 0;
  }

  public static function OSUserExists($user) {
    return self::PathExists("/Local/Default/Users/$user");
  }

  public static function OSGroupExists($user) {
    return self::PathExists("/Local/Default/Groups/$user");
  }

  private static function GetDSPropertyVal($path, $key) {
    $stdout = '';
    Util::ExecC(sprintf("dscl localhost -read %s %s", escapeshellarg($path), escapeshellarg($key)), $stdout);
    $matches = null;
    $result = null;
    if (preg_match("/^$key: ?(.*)$/s", $stdout, $matches) > 0) {
      $result = trim($matches[1]);
    }
    return $result;
  }

  private static function GetDSNodeListByPropertyKeyVal($path, $key, $val) {
    $stdout = '';
    Util::ExecC(sprintf("dscl localhost -list %s %s", escapeshellarg($path), escapeshellarg($key)), $stdout);
    
    $res = array();
    $matches = null;
    $r = preg_match_all('/^(\S+)\s+(\S*)$/m', $stdout, $m);
    
    for ($i = 0; $i < $r; $i ++)
      if ($m[2][$i] == $val)
        $res[] = $m[1][$i];
    return $res;
  }
}

class Uninst {

  public static function PreUninst() {
    try {
      Setup::StopMySQL();
    }
    catch (Exception $__e) {
    }
    try {
      Setup::StopApache();
    }
    catch (Exception $__e) {
    }
    
    if (Util::IsWin()) {
      try {
        self::RemoveXMail();
      }
      catch (Exception $__e) {
      }
    }
    
    self::RemoveHostEntries();
  }

  function RemoveHostEntries() {
    $installDir = self::InstallDir();
    
    $hostsfile = null;
    if (Util::IsWin()) {
      $hostsfile = getenv('SystemRoot') . "\\system32\\drivers\\etc\\hosts";
      $iniFile = $installDir . '/AcquiaDevDesktopControlPanel/dynamic.ini';
    }
    else if (Util::IsOSX()) {
      $hostsfile = "/etc/hosts";
      $iniFile = $installDir . '/Acquia Dev Desktop Control Panel.app/Contents/MacOS/dynamic.ini';
    }
    else {
      die('?');
    }
    
    $ini = Util::ParseIniFile($iniFile);
    $hosts = array();
    foreach ($ini as $section => $vars) {
      $secPath = explode('/', $section);
      if (count($secPath) == 3 && $secPath[0] == 'sites' && $secPath[1] == 'm_sites' && $vars['host'] != 'localhost') {
        $hosts[] = $vars['host'];
      }
    }
    
    if (count($hosts) > 0) {
      $lines = file($hostsfile);
      $lines2 = array();
      foreach ($lines as $line) {
        $remove = false;
        
        foreach ($hosts as $host) {
          if (preg_match('/^127.0.0.1\s+' . preg_quote($host) . '/', $line)) {
            $remove = true;
            break;
          }
        }
        if (! $remove)
          $lines2[] = $line;
      }
      file_put_contents($hostsfile, implode("", $lines2));
      //echo implode( "", $lines2 );
    }
  
  }

  private static function RemoveXMail() {
    $installDir = self::InstallDir();
    if (file_exists($installDir . '\xmail')) {
      $stdout = $stderr = null;
      Util::Exec('net stop XMail', $stdout, $stderr);
      Util::Exec('"' . $installDir . '\xmail\XMail.exe" --remove');
    }
  }

  private static function InstallDir() {
    return realpath(dirname(__FILE__) . '/../..');
  }
}

function main($argv) {
  date_default_timezone_set('UTC');
  try {
    if (isset($argv[1])) {
      if ($argv[1] == 'preuninst') {
        Uninst::PreUninst();
      }
      else if ($argv[1] == 'finishinst') {
        array_shift($argv);
        array_shift($argv);    
        Setup::FinishSiteInstall(Util::ParseArgv($argv));
      }
      else if ($argv[1] == 'diag') {
        $GLOBALS['LOG_OFF'] = true; // log file may not be accessible
        $diag = new Diag();
        $diag->CollectInfo();
      }
    }
    else {
      Setup::Run();
    }
  } 
  catch (Exception $ex) {
    echo $ex->getMessage();
    _Log("Exception caught:" . $ex->getMessage());
    _Log("Trace:" . $ex->getTraceAsString());
    echo "\n" . FAIL_STRING;
    return - 1;
  }
  echo "\n" . SUCCESS_STRING;
  return 0;
}

//
// Drupal installer
//
/** Helper for CLI driven scripts.
 */
class DrupalCli {

  /**
   * Read and setup _SERVER env for running drupal code from CLI
   *
   * Example:
   * DrupalCli::Setup(&$_SERVER)
   *
   * @param array $env $_SERVER
   */
  static public function Setup(&$env) {
    $host = 'localhost';
    $path = '';
    $args = DrupalCli::ParseArguments($env['argv']);
    if (isset($args['url'])) {
      $parse = parse_url($args['url']);
      $host = $parse['host'];
      $path = (array_key_exists('path', $parse) ? $parse['path'] : '');
      if (isset($parse['port']) && is_numeric($parse['port'])) {
        $env['SERVER_PORT'] = $parse['port'];
        $host .= ':' . $parse['port'];
      }
    }
    # useful for debugger where you cannot control current directory
    if (isset($args['chdir'])) {
      chdir($args['chdir']);
    }
    $env['HTTP_HOST'] = $host;
    $env['REMOTE_ADDR'] = '127.0.0.1';
    $env['SERVER_ADDR'] = '127.0.0.1';
    $env['SERVER_SOFTWARE'] = 'PHP/curl';
    $env['SERVER_NAME'] = 'localhost';
    $env['REQUEST_URI'] = $path . '/';
    $env['REQUEST_METHOD'] = 'GET';
    $env['SCRIPT_NAME'] = $path . '/index.php';
    $env['PHP_SELF'] = $path . '/index.php';
    $env['HTTP_USER_AGENT'] = 'Drupal command line';
  }

  /**
   * Parses simple parameters from CLI.
   *
   * Puts trailing parameters into string array in 'extraArguments'
   *
   * Example:
   * $args = DrupalCli::ParseArguments($_SERVER['argv']);
   * if ($args['verbose']) echo "Verbose Mode On\n";
   * $files = $args['extraArguments'];
   *
   * Example CLI:
   * --foo=blah -x -h  some trailing arguments
   *
   * if multiValueMode is true
   * Example CLI:
   * --include=a --include=b --exclude=c
   * Then
   * $args = DrupalCli::ParseArguments($_SERVER['argv']);
   * $args['include[]'] will equal array('a', 'b')
   * $args['exclude[]'] will equal array('c')
   * $args['exclude'] will equal c
   * $args['include'] will equal b   NOTE: only keeps last value
   *
   * @param unknown_type $argv
   * @param supportMutliValue - will store 2nd copy of value in an array with key "foo[]"
   * @return unknown
   */
  static public function ParseArguments($argv, $mutliValueMode = False) {
    $args = array();
    $args['extraArguments'] = array();
    array_shift($argv); // scriptname
    foreach ($argv as $arg) {
      if (ereg('^--([^=]+)=(.*)', $arg, $reg)) {
        $args[$reg[1]] = $reg[2];
        if ($mutliValueMode) {
          DrupalCli::addItemAsArray($args, $reg[1], $reg[2]);
        }
      }
      elseif (ereg('^[-]{1,2}([^[:blank:]]+)', $arg, $reg)) {
        $nonnull = '';
        $args[$reg[1]] = $nonnull;
        if ($mutliValueMode) {
          DrupalCli::addItemAsArray($args, $reg[1], $nonnull);
        }
      }
      else {
        $args['extraArguments'][] = $arg;
      }
    }
    return $args;
  }

  /**
   * Adds a value as an array of one, or appends to an existing array elements
   *
   * @param unknown_type $array
   * @param unknown_type $item
   */
  static function addItemAsArray(&$array, $key, $item) {
    $array_key = $key . '[]';
    if (array_key_exists($array_key, $array)) {
      $array[$array_key][] = $item;
    }
    else {
      $array[$array_key] = array($item);
    }
  }

  static function AssertInDocRoot() {
    if (! file_exists('includes/bootstrap.inc')) {
      acqThrow("Must be run from web docroot. Current working directory is:\n" . getcwd());
    }
  }
}

/**
 * Installs Drupal by screen scraping installer pages.  Only tests on D6 and D7
 */
class DrupalInstaller {
  public $additionalParams = array();
  public $site_name;
  public $email;
  public $username;
  public $password;
  public $profile;
  public $db_path;
  public $db_user;
  public $db_pass;
  public $db_host;
  public $db_port;
  public $db_prefix;

  public function install($baseurl, $confDb = true) {
    $userAgent = new DrupalUserAgent($baseurl);
    $this->run($userAgent, $confDb);
  }

  public function run(DrupalUserAgent $userAgent, $confDb = true) {
    _Log("DWI: begin");
    $i = 1;
    $done = False;
    // hit landing page first
    $html = $userAgent->get("install.php");
    
    $dom = DrupalUserAgent::asDom($html);
    $form = DrupalFormLoader::findById($dom, 'install-select-profile-form');
    if ($form) {
      _Log("DWI: Found select-profile form");
      $checked = null;
      $profiles = $form->getRadioGroup("profile", $checked);
      _Log("DWI: Available profies: " . implode(',', $profiles));
      if (! in_array($this->profile, $profiles)) {
        $this->profile = $checked ? $checked : $profiles[0];
      }
      _Log("DWI: Using profile: {$this->profile}");
      //
    }
    
    $i = 0;
    while (! $done) {
      if ($i ++ > 30) {
        _Log("DWI: Modules installation cycle limit reached.\nLast page html:$html");
        acqThrow("Maximum number of calls made. Site may not be setup correctly.");
      }
      // Implies english locale
      $html = $userAgent->get("install.php?profile=" . $this->profile . "&locale=en&id=1&op=do_nojs");
      _Log("DWI: Step $i...");
      
      if ($confDb && preg_match('/Database configuration/', $html)) {
        _Log('DWI: Database configuration page detected.');
        $dom = DrupalUserAgent::asDom($html);
        $this->configureDatabase($dom, $userAgent);
      }
      else {
        $done = strpos($html, 'Remaining 0') !== false || strpos($html, '<div class="percentage">100%</div>') !== false;
        _Log("Are we done with the modules installation?....%s\n", ($done ? "yes" : "no"));
      }
    }
    
    _Log('DWI: Loading op=finished page...');
    $userAgent->get("install.php?profile=" . $this->profile . "&locale=en&id=1&op=finished");
    
    _Log('DWI: Loading site configuration page...');
    $dom = $userAgent->getDom("install.php?profile=" . $this->profile . "&locale=en");
    $formId = "install-configure-form";
    $form = DrupalFormLoader::findById($dom, $formId);
    if ($form == NULL) {
      _Log("DWI:" . $dom->asXML());
      acqThrow("Could not find installation form in installation screen");
    }
    _Log("DWI: Found $formId form");
    $form->setValue('site_name', $this->site_name);
    $form->setValue('site_mail', $this->email);
    $form->setValue('account[name]', $this->username);
    $form->setValue('account[mail]', $this->email);
    $form->setValue('account[pass][pass1]', $this->password);
    $form->setValue('account[pass][pass2]', $this->password);
    $form->setValue('clean_url', '1');
    $fields = array_merge($form->getParams(), $this->additionalParams);
    $html = $userAgent->post($form->getAction() . '&zxc', $fields);
    if (! preg_match('/complete/', $html)) {
      _Log("DWI:$html");
      acqThrow('Configuration not complete');
    }
    _Log("DWI: finished.");
  }

  function configureDatabase($dom, DrupalUserAgent $userAgent) {
    $formId = "install-settings-form";
    $form = DrupalFormLoader::findById($dom, $formId);
    if ($form == NULL) {
      _Log("DWI:" . $dom->asXML());
      acqThrow("Could not find installation '$formId' form in installation screen");
    }
    $params = $form->getParams();
    if (array_key_exists('db_path', $params)) {
      // drupal6
      $form->setValue('db_path', $this->db_path);
      $form->setValue('db_user', $this->db_user);
      $form->setValue('db_pass', $this->db_pass);
      $form->setValue('db_host', $this->db_host);
      $form->setValue('db_port', $this->db_port);
      $form->setValue('db_prefix', $this->db_prefix);
    }
    else if (array_key_exists('database', $params)) {
      // drupal7 prior to rc1
      $form->setValue('database', $this->db_path);
      $form->setValue('username', $this->db_user);
      $form->setValue('password', $this->db_pass);
      $form->setValue('host', $this->db_host);
      $form->setValue('port', $this->db_port);
      $form->setValue('db_prefix', $this->db_prefix);
    }
    else if (array_key_exists('mysql[database]', $params)) {
      // drupal7 rc1 and later
      $form->setValue('mysql[database]', $this->db_path);
      $form->setValue('mysql[username]', $this->db_user);
      $form->setValue('mysql[password]', $this->db_pass);
      $form->setValue('mysql[host]', $this->db_host);
      $form->setValue('mysql[port]', $this->db_port);
      $form->setValue('mysql[db_prefix]', $this->db_prefix);
    }
    else {
      acqThrow('Unknown database setup form');
    }
    $userAgent->post($form->getAction(), $form->getParams());
  }

  static function array_remove(&$array, $item) {
    $value = null;
    if (array_key_exists($item, $array)) {
      $value = $array[$item];
      unset($array[$item]);
    }
    return $value;
  }
}

class DrupalUpgrader {

  public function upgrade($baseurl) {
    $userAgent = new DrupalUserAgent($baseurl);
    
    $sv = SetupVars::get();
    $stFile = $sv->SITE_DIR . '/sites/default/settings.php';
    
    $c = Util::RegexReplaceInFile($stFile, '/^[^*]*\$update_free_access\s*=\s*false.*$/mi', "\$update_free_access=true;");
    
    $ex = null; // emulating 'finally' block
    try {
      $this->run($userAgent);
    }
    catch (Exception $_e) {
      $ex = $_e;
    }
    
    if ($c > 0) {
      Util::RegexReplaceInFile($stFile, '/^\$update_free_access=true;$/mi', "\$update_free_access=false;");
    }
    
    if ($ex)
      throw $ex;
  }

  public function run(DrupalUserAgent $userAgent) {
    _Log("DWI: update started.");
    $done = False;
    
    $dom = $userAgent->getDom("update.php?op=info");
    if (! $dom) {
      acqThrow("DOM is null");
    }
    
    $form = DrupalFormLoader::findBySubmitValue($dom, "Continue");
    if (! $form) {
      acqThrow("form is null");
    }
    
    $html = $userAgent->post($form->getAction(), array());
    
    if (strpos($html, "No pending updates") !== false) {
      _Log("DWI: No pending updates");
      return;
    }
    
    $dom = DrupalUserAgent::asDom($html);
    if (! $dom) {
      acqThrow("DOM is null");
    }
    
    $form = DrupalFormLoader::findById($dom, "update-script-selection-form");
    if (! $form) {
      acqThrow("form is null");
    }
    
    $params = $form->getParams();
    $a = $form->getAction();
    $html = $userAgent->post($a, $params);
    
    $matches = null;
    $n = preg_match('/<meta\s+http-equiv="Refresh".*URL=.*update\.php\?(.*)"/i', $html, $matches);
    $refreshUrlParams = html_entity_decode($matches[1]);
    
    $i = 0;
    while (! $done) {
      if ($i ++ > 10) {
        _Log("DWI:$html");
        acqThrow("Maximum number of calls made. Site may not be setup correctly.");
      }
      
      // Implies english locale
      $html = $userAgent->get("update.php?$refreshUrlParams");
      
      $n = preg_match('/<meta\s+http-equiv="Refresh".*URL=.*update\.php\?(.*)"/i', $html, $matches);
      if ($n)
        $refreshUrlParams = html_entity_decode($matches[1]);
      
      _Log("DWI: Step $i...\n");
      
      if (preg_match('/Updates were attempted/', $html)) {
        break;
      }
    }
    _Log("DWI: update finished.");
  }

}

/**
 * GET and POST form on drupal, preserving existing form data
 */
class DrupalUserAgent {
  private $handle;
  private $base_url;
  private $cookie_jar;

  public function __construct($base_url = '') {
    $this->handle = curl_init();
    $this->base_url = rtrim(trim($base_url), '/');
    $this->cookie_jar = tempnam(sys_get_temp_dir(), 'dds');
  }

  public function __destruct() {
    $this->close();
    if (file_exists($this->cookie_jar))
        unlink($this->cookie_jar);
  }

  public function close() {
    if ($this->handle) {
      curl_close($this->handle);
      unset($this->handle);
    }
  }

  static public function asDom($content) {
    if (strlen($content) == 0) {
      return null;
    }
    $html = DOMDocument::loadHTML($content);
    if (is_null($html)) {
      acqThrow("Could not parse HTML at url " . $url);
    }
    return simplexml_import_dom($html);
  }

  public function getDom($url) {
    $content = $this->get($url);
    return self::asDom($content);
  }

  public function get($url) {
    $url = str_replace(' ', '%20', $url);
    $absUrl = $this->absoluteUrl($url);
    _Log("DWI: GET(%s)", $absUrl);
    $this->curlOpen($url);
    curl_setopt($this->handle, CURLOPT_HTTPGET, True);
    return $this->curlExecute();
  }

  public function post($url, $params) {
    $url = str_replace(' ', '%20', $url);
    $absUrl = $this->absoluteUrl($url);
    _Log("DWI: POST(%s). params:%s", $absUrl, print_r($params, true));
    $this->curlOpen($url);
    curl_setopt($this->handle, CURLOPT_POST, count($params));
    curl_setopt($this->handle, CURLOPT_POSTFIELDS, DrupalUserAgent::paramString($params));
    return $this->curlExecute();
  }

  public static function paramString($params) {
    $post = array();
    if (! empty($params)) {
      foreach ($params as $field => $value) {
        $post[] = urlencode($field) . '=' . urlencode($value);
      }
    }
    return implode($post, '&');
  }

  public function curlOpen($url) {
    $absUrl = $this->absoluteUrl($url);
    $options = array(
        CURLOPT_URL => $absUrl, 
        //CURLOPT_VERBOSE => TRUE,
        CURLOPT_COOKIEJAR => $this->cookie_jar,
        CURLOPT_COOKIEFILE => $this->cookie_jar,
        CURLOPT_TIMEOUT => 300, 
        CURLOPT_FOLLOWLOCATION => TRUE, 
        CURLOPT_RETURNTRANSFER => TRUE, 
        CURLOPT_USERAGENT => 'drupal-user-agent');
    curl_setopt_array($this->handle, $options);
  }

  public function setCookies($cookies) {
    curl_setopt($this->handle, CURLOPT_COOKIE, DrupalUserAgent::paramString($params));
  }

  public function curlExecute() {
    $fp = null;
    
    if (0) { // debug
      curl_setopt( $this->handle, CURLOPT_VERBOSE, TRUE );
      $fp = fopen(sys_get_temp_dir()."/curl.log", "a");
      curl_setopt( $this->handle, CURLOPT_STDERR, $fp );
       fprintf($fp, "\n1=======================================\n");
    }

    $content = curl_exec($this->handle);
    
    if ($fp) { // debug
      fwrite($fp, "\n2=======================================\n");
      fwrite($fp, $content);
      fwrite($fp, "\n3=======================================\n");
      fclose( $fp );
    }
    $err_id = curl_errno($this->handle);
    if ($err_id != 0) {
      acqThrow(sprintf("curl error: %s (%d)", curl_error($this->handle), curl_errno($this->handle)));
    }
    _Log("DWI: returned html:");
    _Log("========================\n$content\n========================");
    return $content;
  }


  public function absoluteUrl($url) {
    if (empty($url)) {
      return $this->base_url;
    }

    if (empty($this->base_url) || strpos($url, $this->base_url) === 0 || strtolower(substr($url, 0, 7)) == 'http://') {
      return $url;
    }    

    if ($url[0] == '/') {
      $purl = parse_url($this->base_url);
      if (empty($purl['port']))
        $purl['port'] = 80;
      $result = sprintf("%s://%s:%d%s", $purl['scheme'], $purl['host'], $purl['port'], $url);
    }
    else {
      $result = $this->base_url . '/' . $url;
    }

    return $result;
  }
}

class DrupalFormLoader {
  private $form;
  private $params = array();

  public function __construct($form) {
    $this->form = $form;
  }

  /**
   * @return DrupalFormLoader
   */
  public static function findById($dom, $id) {
    $elems = $dom->elements->xpath("//form[@id='" . $id . "']");
    return empty($elems) ? NULL : new DrupalFormLoader($elems[0]);
  }

  public static function findBySubmitValue($dom, $submit) {
    $elems = $dom->elements->xpath("//input[@type='submit' and @value='" . $submit . "']");
    if (! $elems) {
      return NULL;
    }
    foreach ($elems as $elem) {
      $form = $elem->xpath('ancestor::form');
      if (! empty($form)) {
        return new DrupalFormLoader($form[0]);
      }
    }
  }

  public function setValue($name, $value) {
    $this->params[$name] = $value;
  }

  public function getAction() {
    return (string) $this->form['action'];
  }

  public function getRadioGroup($name, &$checked) {
    $checked = null;
    
    $result = array();
    $elements = $this->form->xpath("descendant::input[@type='radio' and @name='$name']");
    foreach ($elements as $el) {
      $result[] = (string) $el['value'];
      if (isset($el['checked']))
        $checked = (string) $el['value'];
    }
    return $result;
  }

  public function getParams() {
    // surely there is more types, only understand these at the moment
    $elements = $this->form->xpath("descendant::input|descendant::textarea|descendant::select");
    foreach ($elements as $element) {
      // SimpleXML objects need string casting all the time.
      $name = (string) $element['name'];
      // ignore preset parameters
      if (empty($name) || /*array_key_exists($name, $this->params)*/ isset($this->params[$name])) {
        continue;
      }
      switch ($element->getName()) {
        case 'input' :
          $type = $element['type'];
          switch ($type) {
            case 'radio' :
            case 'checkbox' :
              if (! isset($element['checked'])) {
                break;
              }
            // Deliberate no break.
            default :
              $value = isset($element['value']) ? (string) $element['value'] : '';
              break;
          }
          break;
        case 'textarea' :
          $value = (string) $element;
          break;
        case 'select' :
          $o = $element->xpath("child::option[@selected]");
          $value = (empty($o) ? '' : (string) $o[0]['value']);
          break;
      }
      $this->params[$name] = $value;
    }
    return $this->params;
  }
}

function install_drupal($args) {
  DrupalCli::Setup($_SERVER);
  $installer = new DrupalInstaller();
  $installer->profile = @$args['profile'];
  $installer->db_path = @$args['db_path'];
  $installer->db_user = @$args['db_user'];
  $installer->db_pass = @$args['db_pass'];
  $installer->db_host = @$args['db_host'];
  $installer->db_port = @$args['db_port'];
  $installer->db_prefix = @$args['db_prefix'];
  $installer->site_name = @$args['site_name'];
  $installer->username = @$args['username'];
  $installer->password = @$args['password'];
  $installer->email = @$args['email'];
  //$installer->additionalParams = $args;
  $installer->install(@$args['baseurl']);
}


$DATA_PHPINFO_PHP_CODE = <<<MYDATA
<?php
if( \$_SERVER['REMOTE_ADDR'] == '127.0.0.1' || \$_SERVER['REMOTE_ADDR'] == '::1')
{
    ob_start();
    phpinfo();
    \$pinfo = ob_get_contents();
    ob_end_clean();
    echo str_replace( '<head>', '<head><link rel="shortcut icon" href="/misc/favicon.ico" type="image/x-icon" />', \$pinfo);
}
?>
MYDATA;



class Diag
{
  private $tmpDir;

  function __construct()
  {
    $this->tmpDir = sys_get_temp_dir();
    if (substr( $this->tmpDir, -1 ) == DIRECTORY_SEPARATOR) {
      $this->tmpDir = substr( $this->tmpDir, 0, -1 );
    }
    $this->tmpDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'acquia_dd_diag';
  }

  public function CollectInfo()
  {
    $sv = SetupVars::get();
    $instDir = $sv->INSTALL_DIR;

    if (file_exists($this->tmpDir)) {
      Util::RmDir($this->tmpDir);
    }
    mkdir($this->tmpDir);

    if (Util::IsOSX()) {
      $cpIniPath = 'Acquia Dev Desktop Control Panel.app/Contents/MacOS';
      $apacheErr = 'error_log';
      $apacheAcs = 'access_log';
      $phpIni = 'bin/php.ini';
      $arc = getenv('HOME') . '/acquia_dd_diag.zip';
    }
    else if (Util::IsWin ()) {
      $cpIniPath = 'AcquiaDevDesktopControlPanel';
      $apacheErr = 'error.log';
      $apacheAcs = 'access.log';
      $phpIni = 'php.ini';
      $arc = getenv('HOMEDRIVE') . getenv('HOMEPATH') . '\acquia_dd_diag.zip';
    }
    echo "Collecting information...\n";
    // installer logs
    copy($instDir . '/installer.log', $this->tmpDir . '/installer.log');
    copy($instDir . '/piscript.log', $this->tmpDir . '/piscript.log');    

    // control panel ini
    copy($instDir . '/' . $cpIniPath . '/static.ini', $this->tmpDir . '/controlpanel_static.ini');
    copy($instDir . '/' . $cpIniPath . '/dynamic.ini', $this->tmpDir . '/controlpanel_dynamic.ini');

    // apache config and logs
    copy($instDir . '/apache/logs/' . $apacheErr, $this->tmpDir . '/apache_error.log');
    copy($instDir . '/apache/logs/' . $apacheAcs, $this->tmpDir . '/apache_access.log');
    copy($instDir . '/apache/conf/httpd.conf', $this->tmpDir . '/apache_httpd.conf');
    copy($instDir . '/apache/conf/vhosts.conf', $this->tmpDir . '/apache_vhosts.conf');

    // mysql config and log
    copy($instDir . '/mysql/my.cnf', $this->tmpDir . '/mysql_my.cnf');
    copy($instDir . '/mysql/data/mysql.err', $this->tmpDir . '/mysql_mysql.err');

    // php ini
    copy($instDir . '/php5_2/' . $phpIni, $this->tmpDir . '/php5_2_php.ini');
    copy($instDir . '/php5_3/' . $phpIni, $this->tmpDir . '/php5_3_php.ini');
    copy($instDir . '/php5_4/' . $phpIni, $this->tmpDir . '/php5_4_php.ini');
    //copy($instDir . '/php/' . $phpIni, $this->tmpDir . '/php_php.ini');

    // xmail spool
    if (Util::IsWin () && file_exists($instDir . '\xmail')) {
      Util::CopyDir($instDir . '\xmail\MailRoot\spool', $this->tmpDir . '\xmail_spool');
    }

    $diagRep = $this->tmpDir . DIRECTORY_SEPARATOR . 'flist.txt';
    $stdout = '';
    if (Util::IsOSX()) {
      $sudoUser = getenv('SUDO_USER');
      Util::ExecC('whoami > ' . escapeshellarg($diagRep), $stdout);
      if ($sudoUser) {
        Util::ExecC("id $sudoUser >> " . escapeshellarg($diagRep), $stdout);
      }
      Util::ExecC('ls -l -R ' . escapeshellarg($instDir) . ' >> ' . escapeshellarg($diagRep), $stdout);
      Util::ExecC('ls -l -R ' . escapeshellarg($sv->SITE_DIR) . ' >> ' . escapeshellarg($diagRep), $stdout);
      file_put_contents($diagRep, "================ Processes ================\n", FILE_APPEND);
      Util::ExecC('ps -axl >> ' . escapeshellarg($diagRep), $stdout);
    }
    else if (Util::IsWin ()) {
      Util::ExecC('cmd.exe /c dir /s ' . escapeshellarg($instDir) . ' > ' . escapeshellarg($diagRep), $stdout);
      Util::ExecC('cmd.exe /c dir /s ' . escapeshellarg($sv->SITE_DIR) . ' >> ' . escapeshellarg($diagRep), $stdout);
      file_put_contents($diagRep, "================ Processes ================\n", FILE_APPEND);
      $qpCmdLine = Util::IsWin64() ? getenv('WinDir') . '\Sysnative\qprocess.exe' : 'qprocess.exe';
      @exec("$qpCmdLine *  >> " . escapeshellarg($diagRep), $stdout); // qprocess may be not available for some systems.
    }
    
    $env = Util::GetAllEnv();
    file_put_contents($diagRep, "=============== Environment ===============\n" . print_r($env, true), FILE_APPEND);
    

    echo "Packaging {$this->tmpDir} to $arc...\n";
    $zip = new ZipArchive();
    $zip->open($arc, ZIPARCHIVE::OVERWRITE);
    $this->zip($this->tmpDir, $zip);
    $zip->close();

    Util::RmDir($this->tmpDir);
  }


  private function zip($folder, ZipArchive $arc, $zip_path = null) {
    if ($zip_path) {
      $arc->addEmptyDir($zip_path);
    }
    $dir = new DirectoryIterator($folder);
    foreach($dir as $file) {
      if(!$file->isDot()) {
        $filename = $file->getFilename();
        if($file->isDir()) {
          $this->zip(
            $folder . DIRECTORY_SEPARATOR . $filename, 
            $arc, 
            $zip_path ? $zip_path. DIRECTORY_SEPARATOR . $filename : $filename);
        }
        else {
          $arc->addFile(
            $folder . DIRECTORY_SEPARATOR . $filename, 
            $zip_path ? $zip_path . DIRECTORY_SEPARATOR . $filename : $filename);
        }
      }
    }
  }

}



//
// Script entry point
//


$r = main($_SERVER['argv']);
exit($r);
?>
