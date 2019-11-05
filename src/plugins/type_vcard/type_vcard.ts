/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

import { VCardEditor } from './vcard_fieldhandler';

(<any>window).vcard_initEditor = (idDialog: string,           // e.g. "editticket"
                                  idControlBase: string,      // e.g. "editticket-vcard"
                                  aSimpleDialogFields: string[],
                                  oArrayDialogFields: any,
                                  fSimpleFormat: boolean) =>
{
    new VCardEditor(idDialog,
                    idControlBase,
                    aSimpleDialogFields,
                    oArrayDialogFields,
                    fSimpleFormat);
};
