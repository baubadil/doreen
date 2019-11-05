<?php

/*
 *  Copyright 2016 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  TestClass class
 *
 ********************************************************************/

/**
 *  The TestCase class uses this for bookkeeping.
 */
class TestClass
{
    public $name;
    public $aMethods = [];
    public $o;

    private $llCurrentlyRunning;         # [ object, methodname ]

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function addMethod($method)
    {
        $this->aMethods[] = $method;
    }

    /**
     *  Executes the given method on the given object, which is assumed
     *  to be an instance of $this->name.
     */
    public function exec($o, $method)
    {
        $classname = $this->name;
        echo "Calling $classname::$method()...\n";
        $this->llCurrentlyRunning = [$o, $method];
        call_user_func($this->llCurrentlyRunning);
        echo "Returned from $classname::$method()\n";
    }
}


/********************************************************************
 *
 *  TestCase class
 *
 ********************************************************************/

/**
 *  Simple test case framework. Instance method names are modelled after PHPUnit
 *  so that it's easy to switch later.
 *
 *  This class does two things: provide a static entry point which scans the source
 *  tree for test cases and runs them, and a base class for test cases to derive from.
 *
 *  To write a test case, name it *_test_*.php, put it somewhere under src/ and
 *  have a class in there that extends from this class. This is grepped for so
 *  the line must be exactly "class NAME extends TestCase" without line breaks.
 *
 *  This code then executes all methods that begin with test*() in that class, in
 *  the order that they appear in the class declaration.
 */
class TestCase
{
    /** @var TestClass[] $aTestcaseClasses  */
    private static $aTestClasses = [];

    private static $cPassed = 0;
    private static $cFailed = 0;


    /********************************************************************
     *
     *  CLI entry point (static)
     *
     ********************************************************************/

    /**
     *  Entry point. This gets called from "cli.php run-tests".
     */
    public static function FindAndRun()
    {
        Globals::SetErrorHandler();

        self::Find();
        self::Run();
    }

    /**
     *  Called from FindAndRun() to find all classes and methods with test cases.
     */
    private static function Find()
    {
        global  $g_aPlugins;

        exec('find -L '.DOREEN_ROOT.'/src/ -name "*_test_*.php"',
             $aTestFiles);

        Globals::EchoIfCli("Active plugins: ".implode(', ', $g_aPlugins));

        /* For every testcase file, grep for classes that extend TestCase, then actually
           require it so that PHP parses it. */
        foreach ($aTestFiles as $testfile)
        {
            $fRun = 1;
            if (    (preg_match('/\/src\/plugins\/([^\/]+)\//', $testfile, $aMatches))
                 && ($plugin = $aMatches[1])
               )
            {
                $fRun = in_array($plugin, $g_aPlugins);
            }

            if (!$fRun)
                Globals::EchoIfCli("Skipping test file $testfile since plugin is disabled");
            else
            {
                Globals::EchoIfCli("Queueing test file $testfile for execution");
                $phpcode = file_get_contents($testfile);
                if (preg_match('/class\s+(\S+)\s+extends\s+TestCase/', $phpcode, $aMatches))
                {
                    $classname = 'Doreen\\'.$aMatches[1];
                    self::$aTestClasses[$classname] = new TestClass($classname);
                }

                require $testfile;
            }
        }

        /* Now use PHP's own inspection to get the methods of the test classes. */
        foreach (self::$aTestClasses as $oClass)
        {
            $clsname = $oClass->name;
            foreach (get_class_methods($clsname) as $method)
            {
                if (preg_match('/^test/', $method))
                    self::$aTestClasses[$clsname]->addMethod($method);
            }
        }
    }

    /**
     *  Called from FindAndRun() to execute all testcases that were found.
     */
    private static function Run()
    {
        foreach (self::$aTestClasses as $oClass)
        {
            $classname = $oClass->name;
            $o = new $classname;

            foreach ($oClass->aMethods as $method)
                $oClass->exec($o, $method);
        }

        $cTotal = self::$cPassed + self::$cFailed;
        echo "$cTotal tests run in total, ".self::$cPassed." passed, ".self::$cFailed." failed.\n";
    }


    /********************************************************************
     *
     *  Instance methods to be used by test cases
     *
     ********************************************************************/

    /**
     *  If $f is TRUE, considers a test successful. If $f is FALSE, the test
     *  is considered failed, and $message is also printed.
     */
    public function assert($f, $errorMessage)
    {
        if ($f)
            ++self::$cPassed;
        else
        {
            ++self::$cFailed;
            echo("Assertion failed: $errorMessage\n");
            exit(2);
        }
    }

    /**
     *  Compares $expected to $actual and prints $mesage if they are not equal.
     */
    public function assertEquals($expected,
                                 $actual,
                                 $errorMessage = NULL)
    {
        $this->assert($expected == $actual,
                      ($errorMessage) ? $errorMessage : "\nExpected value \"$expected\",\n"
                                                         ."       but got \"$actual\"");
    }

    public function assertNotEmpty($v,
                                   $errorMessage = NULL)
    {
        $this->assert(!!$v,
                      ($errorMessage) ? $errorMessage : "Expected value \"$v\" to be empty but it's not");
    }

    public function assertNotNULL($v,
                                  $errorMessage = NULL)
    {
        $this->assert(!!$v,
                      ($errorMessage) ? $errorMessage : "Expected value \"$v\" is NULL");
    }
}
