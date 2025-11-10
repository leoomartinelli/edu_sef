<?php
// gerar_hash.php
$senhaEmTextoPuro = 'admin123';
$hash = password_hash($senhaEmTextoPuro, PASSWORD_BCRYPT);
echo $hash;
?>