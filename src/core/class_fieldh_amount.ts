/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import { Big } from 'big.js';
import { drnFindByID } from 'core/shared';

export class SubAmountHandler
{
    private oEntryFieldSum: JQuery;
    private aSubEntryFields = {};

    constructor(private idEntryFieldStem: string,  //!< in: prefix to which to append -sum or -id
                private aCategoryIDs: number[])
    {
        this.oEntryFieldSum = drnFindByID(this.idEntryFieldStem + '-sum');
        for (let idCat of aCategoryIDs)
        {
            let oThis = drnFindByID(idEntryFieldStem + '-' + idCat);
            oThis.on('input', () => {
                this.updateSum();
            });
            this.aSubEntryFields[idCat] = oThis;
        }
    }

    private updateSum()
    {
        let total = new Big(0);

        for (let id in this.aSubEntryFields)
            if (this.aSubEntryFields.hasOwnProperty(id))
            {
                let oSubAmount = this.aSubEntryFields[id];
                let val = oSubAmount.val();
                if (val)
                    total = total.plus(val);
            }

        let valSum = '';
        if (total)
            valSum = total.toFixed(2);
        this.oEntryFieldSum.val(valSum);
    }
}
