
<?php

require_once __DIR__ . '/config/python_bin.php';

header('Content-Type: text/plain; charset=utf-8');

$python = resolverBinarioPython();

echo "RUTA PYTHON: " . $python . PHP_EOL;

echo "EXISTE: ";
echo is_file($python) ? "SI" : "NO";
echo PHP_EOL;

echo "ES EJECUTABLE: ";
echo is_executable($python) ? "SI" : "NO";
echo PHP_EOL;

$comando = escapeshellarg($python) . ' --version 2>&1';

$salida = [];
$codigo = -1;

exec($comando, $salida, $codigo);

echo "SALIDA:" . PHP_EOL;
echo implode(PHP_EOL, $salida);
echo PHP_EOL;

echo "CODIGO: " . $codigo;
