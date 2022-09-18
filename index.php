<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
<title>Labeler</title>
</head>
<?php
exec("ptouch-print --info 2>&1", $output, $retval);
global $tapeSize;
$tapeSize=$output[3];
if(strpos($tapeSize, "No media") !== false) {
	$tapeSize = "None";
} else {
	$tapeSize=str_replace("media width = ", "", $tapeSize);
}

global $preview;
$preview=False;
global $line_one;
$line_one="";
global $line_two;
$line_two="";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$line_one=$_POST['line_one'];
	$line_two=$_POST['line_two'];
	$cmd="ptouch-print --text";
	if(!empty($line_one)){
		$cmd=$cmd." \"".$line_one."\""; 
	}
	if(!empty($line_two)){
		$cmd=$cmd." \"".$line_two."\"";
	}

	if ($_POST['action'] == 'print') {
		exec("$cmd 2>&1", $output, $retval);
	} else if ($_POST['action'] == 'preview') {
		$preview=True;
		$cmd=$cmd." --writepng '/srv/http/preview.png'";
		$output="";
		$retVal=0;
		exec("$cmd 2>&1", $output, $retval);
		if ($retval != 0) {
			print("<h1>ERROR!</h1>");
			foreach($output as $line) {
				print($line);
				print("<br/>");
			}
			print("<br/>");
			print("<br/>");
		}
	}
}	
?>
<body>
	<div class="form">
		<div class="title">Labeler</div>
		<div class="subtitle"><?php print("Tape size: ".$tapeSize); ?></div>
		<div class="cut cut-long"></div>
		<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
    			<div class="input-container ic1">
      				<input class="input" type="text" id="line_one" name="line_one" onload='this.click();' value="<?php print($line_one); ?>" placeholder="" />
				<div class="cut"></div>
	      			<label for="line_one" class="placeholder">Line 1</label>
    			</div>
			<div class="input-container ic2">
      				<input class="input" type="text" id="line_two" name="line_two" placeholder="" value="<?php print($line_two); ?>" />
				<div class="cut"></div>
      				<label for="line_two" class="placeholder">Line 2</label><br>
    			</div>
			<div class="cut cut-short"></div>
			<button type="submit" name="action" value="preview" class="submit">Preview</button>
			<?php
				if ($preview) {
					$filename="preview.png";
					if (file_exists($filename)) {
						print('<div class="cut"></div>');
						print('<div class="input-container ic3">');
						print("<img width=100% src=$filename />");
						print('</div>');
						print('<div class="cut"></div>');
					}
				}
			?>
			<button type="submit" name="action" value="print" class="submit">Print</button>
		</form>
	</div>
</body>
</html>

