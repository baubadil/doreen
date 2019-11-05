<?php
$language = 'de_DE.UTF-8';
$rc = putenv("LC_MESSAGES=$language");
if (!$rc)
    echo "putenv() failed\n";

$rc = setlocale(LC_MESSAGES, $language);
echo "setlocale returned \"$rc\"\n";
if ($rc === FALSE)
    echo "FALSE\n";

$domain = 'doreen';
$rc = bindtextdomain($domain, 'locale');
echo "bindtextdomain returned \"$rc\"\n";
$rc = textdomain($domain);
echo "textdomain returned \"$rc\"\n";

echo "\""._("Users and groups")."\"\n";

