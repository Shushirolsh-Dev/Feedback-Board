<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$required=['public/index.php','public/router.php','public/assets/styles.css','README.md','Dockerfile','render.yaml','.env.example'];
$errors=[];
foreach($required as $file)if(!is_file($root.'/'.$file))$errors[]="Missing file: ".$file;
$source=file_get_contents($root.'/public/index.php');
foreach(['CREATE TABLE IF NOT EXISTS feedback','CREATE TABLE IF NOT EXISTS comments','CREATE TABLE IF NOT EXISTS votes','verifyCsrf','password_verify','/api/feedback'] as $marker)if(!str_contains($source,$marker))$errors[]="Missing feature marker: ".$marker;
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "Feedback Board smoke checks passed.\n";
