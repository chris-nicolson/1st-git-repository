 <?php 
	echo "Hi<br /> <p>as long as it is inside the quotes</p>";
	echo "Yes this is actually working";
	
	//Variables!
	$x = 5;  //integer
	$y = 10;  //integer
	$z = 25.5;  //float
	$xyz = "This is a string.";
	
	$abc = array('apple', 'orange', 'banana', 'grapefruit');  //numerically
	$more_food = array('meat' => 'steak', 'veggies' => 'carrots', 'dairy' => 'ice-cream','serving' => 3);  //assosiative array
?>
	<div id="my-page">
	<?php if (!empty($x)) { ?>
		<p>More Stuff - <?php echo $x; ?></p>
		<?php } ?>
		</div>
