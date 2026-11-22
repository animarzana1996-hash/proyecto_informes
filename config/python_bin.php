
<?php

function resolverBinarioPython(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 'C:\\Users\\anima\\AppData\\Local\\Programs\\Python\\Python313\\python.exe';
    }

    return 'python3';
}
