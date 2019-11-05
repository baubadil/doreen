/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

import entry from 'core/entry/entry';
import EntryPoint from 'core/entry/entrypoint';
import { SchoolCreateClass } from './school_class';
import { SchoolParentsEditor } from './school_fieldh_parents';
import { SchoolSetPassword } from './view_welcome/school_welcome';

(<any>window).school_initCreateClass = (idDialog: string) =>
{
    new SchoolCreateClass(idDialog);
};

(<any>window).school_initParentsEditor = (idEntryField: string) =>
{
    new SchoolParentsEditor(idEntryField);
};

(<any>window).school_initSetPassword = (idDialog: string) =>
{
    new SchoolSetPassword(idDialog);
};
