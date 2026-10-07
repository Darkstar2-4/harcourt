<?php


$generatedDir = __DIR__ . '/../docs/generated';
$outputFile = __DIR__ . '/../docs/README.md';
$coreDir = __DIR__ . '/../core';
$phpdocMdFile = __DIR__ . '/../.phpdoc-md';

if (!is_dir($generatedDir)) {
    echo "Error: Generated documentation directory not found at {$generatedDir}\n";
    exit(1);
}

$files = glob($generatedDir . '/*.md');
if (empty($files)) {
    echo "Warning: No markdown files found in {$generatedDir}, will attempt to generate from PHP files\n";
    $files = [];
}

$phpdocMdConfig = include($phpdocMdFile);
$configuredClasses = [];
foreach ($phpdocMdConfig->classes as $class) {
    $className = basename(str_replace('\\', '/', $class));
    $configuredClasses[] = $className;

    $mdFile = $generatedDir . '/' . $className . '.md';
    if (!file_exists($mdFile) && !in_array($mdFile, $files)) {
        echo "Class {$className} is in .phpdoc-md but no markdown file was generated. Creating one...\n";

        $phpFile = $coreDir . '/' . $className . '.php';

        if ($className === 'Str' && !file_exists($phpFile)) {
            $phpFile = $coreDir . '/Strings.php';
        }

        if (file_exists($phpFile)) {
            $phpContent = file_get_contents($phpFile);

            $classDescription = "";
            if (preg_match('/\/\*\*\s*(.*?)\s*\*\//s', $phpContent, $matches)) {
                $classDescription = $matches[1];
                $classDescription = preg_replace('/\s*\*\s*@.*$/m', '', $classDescription);
                $classDescription = preg_replace('/\s*\*\s*/m', ' ', $classDescription);
                $classDescription = trim($classDescription);
            }

            $methods = [];
            preg_match_all('/@method\s+static\s+(?:callable|mixed|string|array|bool|int|[^\s]+)\s+([a-zA-Z0-9_]+)\s*\((.*?)\)(.*?)(?=\*\s+@method|\*\/)/s', $phpContent, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $methodName = $match[1];
                $signature = trim($match[2]);
                $description = trim($match[3]);

                $description = preg_replace('/\s*-\s*Curried\s*::\s*/', "\n\nCurried :: ", $description);
                $description = preg_replace('/\*\s+/', '', $description);

                $methods[$methodName] = [
                    'signature' => $signature,
                    'description' => $description
                ];
            }

            $content = "# {$className}\n\n";
            if ($classDescription) {
                $content .= "{$classDescription}\n\n";
            }

            foreach ($methods as $methodName => $methodInfo) {
                if ($methodName !== 'init' && $methodName !== 'macro' && $methodName !== 'hasMacro') {
                    $content .= "### {$className}::{$methodName}\n\n";
                    $content .= "**Description**\n\n";
                    $content .= "{$methodInfo['description']}\n\n";
                    $content .= "```php\n";
                    $content .= "public static function {$methodName}({$methodInfo['signature']})\n";
                    $content .= "```\n\n";
                }
            }

            file_put_contents($mdFile, $content);
            $files[] = $mdFile;
            echo "Created markdown file for {$className} with " . count($methods) . " methods\n";
        } else {
            echo "Warning: Could not find PHP file for class {$className}\n";
        }
    }
}

sort($files);

$content = "# WPML Functional Programming Library\n\n";
$content .= "## Table of Contents\n\n";

$tocEntries = [];
$fileContents = [];
$methodsByClass = [];

function extractPhpDocMethods($filePath) {
    $content = file_get_contents($filePath);
    $methods = [];

    preg_match_all('/@method\s+static\s+(?:callable|mixed|string|array|bool|int|[^\s]+)\s+([a-zA-Z0-9_]+)\s*\(/s', $content, $matches);

    echo "Extracting methods from " . basename($filePath) . ":\n";
    if (!empty($matches[1])) {
        foreach ($matches[1] as $method) {
            if (!in_array($method, ['init', 'macro', 'hasMacro'])) {
                $methods[] = $method;
                echo "  - Found method: " . $method . "\n";
            }
        }
    } else {
        echo "  - No methods found\n";
    }

    return $methods;
}

foreach ($files as $file) {
    $className = basename($file, '.md');
    $fileContent = file_get_contents($file);

    echo "Processing file: " . $file . " (class: " . $className . ")\n";

    $phpFile = $coreDir . '/' . $className . '.php';

    if ($className === 'Str' && !file_exists($phpFile)) {
        $phpFile = $coreDir . '/Strings.php';
    }

    $phpDocMethods = [];

    if (file_exists($phpFile)) {
        echo "Found corresponding PHP file: " . $phpFile . "\n";
        $phpDocMethods = extractPhpDocMethods($phpFile);
    } else {
        echo "No corresponding PHP file found for " . $className . "\n";
    }

    preg_match_all('/^### ' . $className . '::([a-zA-Z0-9_]+)\s*$/m', $fileContent, $matches);
    $methods = [];

    if (!empty($matches[1])) {
        foreach ($matches[1] as $method) {
            $methods[] = $method;
            $methodsByClass[$className][] = $method;
        }
    }

    if (!empty($phpDocMethods)) {
        foreach ($phpDocMethods as $method) {
            if (!in_array($method, $methods) && $method !== 'init' && $method !== 'macro' && $method !== 'hasMacro') {
                $methodsByClass[$className][] = $method;
            }
        }
    }

    $lines = explode("\n", $fileContent);
    array_shift($lines);

    $cleanedContent = '';
    $inMethod = false;
    $methodName = '';

    foreach ($lines as $line) {
        if (preg_match('/^### ' . $className . '::([a-zA-Z0-9_]+)\s*$/m', $line, $methodMatch)) {
            $methodName = $methodMatch[1];
            $inMethod = true;
            $cleanedContent .= "### {$methodName}\n\n";
            continue;
        }

        if (strpos($line, '| Name | Description |') !== false || 
            strpos($line, '|------|-------------|') !== false ||
            strpos($line, '<hr />') !== false) {
            continue;
        }

        if ($inMethod) {
            if (strpos($line, '**Description**') !== false) {
                continue;
            }

            if (strpos($line, '```php') !== false) {
                continue;
            }
            if (strpos($line, 'public') !== false && strpos($line, '(') !== false) {
                continue;
            }
            if (strpos($line, '```') !== false) {
                continue;
            }

            $cleanedContent .= $line . "\n";
        }
    }

    if (!empty($phpDocMethods)) {
        foreach ($phpDocMethods as $method) {
            if (!in_array($method, $methods) && $method !== 'init' && $method !== 'macro' && $method !== 'hasMacro') {
                $cleanedContent .= "### {$method}\n\n";

                $phpContent = file_get_contents($phpFile);
                if (preg_match('/@method\s+static\s+(?:callable|mixed|[^\s]+)\s+' . $method . '\s*\((.*?)\)(.*?)(?=\*\s+@method|\*\/)/s', $phpContent, $docMatch)) {
                    $signature = trim($docMatch[1]);
                    $description = trim($docMatch[2]);

                    $description = preg_replace('/\s*-\s*Curried\s*::\s*/', "\n\nCurried :: ", $description);
                    $description = preg_replace('/\*\s+/', '', $description);

                    $cleanedContent .= "**Signature:** `{$method}({$signature})`\n\n";
                    $cleanedContent .= "{$description}\n\n";
                } else {
                    $cleanedContent .= "This method is documented in PHPDoc annotations but not in the generated markdown file.\n\n";
                }
            }
        }
    }

    $fileContents[$className] = $cleanedContent;
}

foreach ($methodsByClass as $className => $methods) {
    $tocEntries[] = "* [{$className}](#{$className})";
    foreach ($methods as $method) {
        $lowerMethod = strtolower($method);
        $tocEntries[] = "    * [{$method}](#{$lowerMethod})";
    }
}

$content .= implode("\n", $tocEntries) . "\n\n";

foreach ($fileContents as $className => $fileContent) {
    $content .= "* {$className}\n" . $fileContent . "\n";
}

if (file_put_contents($outputFile, $content)) {
    echo "Documentation successfully merged into {$outputFile}\n";

    $files = glob($generatedDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (rmdir($generatedDir)) {
        echo "Generated directory {$generatedDir} has been removed\n";
    } else {
        echo "Warning: Could not remove generated directory {$generatedDir}\n";
    }
} else {
    echo "Error: Failed to write to {$outputFile}\n";
    exit(1);
}
