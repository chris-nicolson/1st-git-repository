�
    @  &�         � 	      !        &  ��          //  0                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             +       �    ( �    �    �      	�:        �6   i �   �=  ��     ��   �PRIMARY�name�access�created�mail�picture�                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                �                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      InnoDB                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            `                                           Stores user data.                                                                                                                                                                                                  � �  �v         P   �  � )                                          uid  name  pass  mail  theme 	 
signature 
 signature_format  created  access  login  status  	timezone  	language  picture  init  data 

       ! J�        ! J��       ! J�=  �   ! I�9      ! 	
E�8      ! 
>�7  �   !*  6     !$  :     !3  >     !"  B     !, 	F` C  �   ! 	$$ �      !  �     !2 J��  �   !1  � �  �?� �uid�name�pass�mail�theme�signature�signature_format�created�access�login�status�timezone�language�picture�init�data� Primary Key: Unique user ID.Unique user name.User’s password (hashed).User’s e-mail address.User’s default theme.User’s signature.The filter_format.format of the signature.Timestamp for when user was created.Timestamp for previous time user accessed the site.Timestamp for user’s last login.Whether the user is active(1) or blocked(0).User’s time zone.User’s default language.Foreign key: file_managed.fid of user’s picture.E-mail address used for initial account creation.A serialized array of name value pairs that are related to the user. Any form values posted during user edit are stored and are loaded into the $user object during user_load(). Use of this field is discouraged and it will likely disappear in a future...