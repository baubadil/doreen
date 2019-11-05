/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as Slider from 'bootstrap-slider';
import { drnFindByID } from './shared';

/**
 *  Wrapper class around bootstrap-slider. This takes an input type=text and
 *  creates a Slider object for it.
 *
 *  The bootstrap-slider control hides that entry field and automatically updates
 *  its value on changes. As a result, the slider control works in forms and our
 *  dialogs without changes.
 */
export default class DrnBootstrapSlider
{
    private oSlider: any;

    private static oAllSliders: any = {};

    constructor(jqEntryForSlider: JQuery,           //!< in: input type=text to replace with slider
                private value: number,         //!< in: initial value of the slider
                iMin: number,                   //!< in: minimum value of the slider
                iMax: number)                   //!< in: maximum value of the slider
    {
        if (!jqEntryForSlider.length)
            throw "slider JQ object is empty";
        this.oSlider = new Slider(jqEntryForSlider[0],
                                   {   min: iMin,
                                       max: iMax,
                                       step: 1,
                                       value: value,
                                       ticks: [ iMin, iMax ],
                                   }).on('change', (event) => {
                                       this.value = event.newValue;
                                   });

        let id = jqEntryForSlider.attr('id');
        if (id)
            DrnBootstrapSlider.oAllSliders[id] = this;
    }

    public getValue(): number
    {
        return this.value;
    }

    public setValue(value: number)
    {
        this.oSlider.setValue(value);
    }

    public static SetValue(idEntryField: string, value: number)
    {
        if (DrnBootstrapSlider.oAllSliders.hasOwnProperty(idEntryField))
        {
            DrnBootstrapSlider.oAllSliders[idEntryField].setValue(value);
        }
    }
}

export function SetBootstrapSlider(idEntryField: string,
                            value: number,
                            fWobble: boolean)
{
    let jqEntryForSlider = drnFindByID(idEntryField);
    if (jqEntryForSlider.length)
    {
        DrnBootstrapSlider.SetValue(idEntryField, value);
        if (fWobble)
            jqEntryForSlider.prev().addClass('animated wobble');
    }
}
