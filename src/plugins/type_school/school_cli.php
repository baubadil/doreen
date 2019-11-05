<?php

/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/**
 *  SchoolCli implements command line functions for the school plugin.
 *
 */
abstract class SchoolCli extends CliBase
{
    const A_ARGS = [                # [ type, no. of extra args, help ]
        'school-create-class' =>    [ self::TYPE_MODE,
                                      1,
                                      '<classname>: Create a new class with the given name (including groups and results)'
                                    ],
                   ];

    protected static function GetArgsData()
    {
        return self::A_ARGS;
    }

    public static function ProcessCommands(PluginSchool $oPlugin)
    {
        if ($mode = self::$mode)
            switch ($mode)
            {
                case 'school-create-class':
                    $name = self::$llMainArgs[0];
                    $oClass = SchoolClass::Create(User::GetFirstAdminOrThrow(),
                                                  NULL,
                                                  $name);

                    echo "Created class ".Format::UTF8Char($name)." with class ID $oClass->id\n";
                break;
            }
    }
}
