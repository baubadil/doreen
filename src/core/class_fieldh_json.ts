/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { drnFindByID } from '../js/shared';

export class JsonAmountsHandler
{
    private aJsonKeys: string[] = [];
    private _jqEntryCombined: JQuery;

    constructor(private _idControl: string,
                _oKeys: any)
    {
        this._jqEntryCombined = drnFindByID(_idControl);

        for (let key in _oKeys)
            if (_oKeys.hasOwnProperty(key))
            {
                let jqSubEntryField = drnFindByID(`${_idControl}-${key}`);
                jqSubEntryField.on('input', () => {
                    this.updateHiddenJson();
                });

                this.aJsonKeys.push(key);
            }
    }

    private updateHiddenJson()
    {
        let oCombined: any = {};
        for (let key of this.aJsonKeys)
        {
            let jqSubEntryField = drnFindByID(`${this._idControl}-${key}`);
            oCombined[key] = jqSubEntryField.val();
        }

        this._jqEntryCombined.val(JSON.stringify(oCombined));
    }
}

