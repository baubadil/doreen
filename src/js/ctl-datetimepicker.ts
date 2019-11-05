/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import * as moment from 'moment';
import * as installDtp from 'bootstrap-datetimepicker-npm';
import { drnFindByID } from './shared';

installDtp($);

declare global {
    interface JQuery {
        datetimepicker(args): JQuery;
    }
}

declare const g_dateTimePickerIcons: any;
declare const g_dateTimePickerTooltips: any;

function getFormatString(format: string)
{
    switch (format)
    {
        case 'LL': return 'YYYY-MM-DD';
        case 'LT': return 'HH:mm';
        case 'LLL': return 'YYYY-MM-DD HH:mm';
    }

    return null;
}

/**
 *  Helper function for the PHP back-end HTMLChunk::addDateTimePicker() function. This connects
 *  the bootstrap-datetimepicker to the hidden entry field via a custom event handler.
 */
export function drnInitDateTimePicker(idHiddenEntryField,  //!< in: ID of hidden entry field to receive updated values
                               idForPicker,         //!< in: ID of DIV around input that will have bootstrap-datetimepicker
                               format,              //!< in: LL for date only, LT for time only, LLLL for date/time
                               locale,              //!< in: locale string (e.g. de_DE)
                               value)               //!< in: initial value
{
    let jqPicker = drnFindByID(idForPicker);
    let jqHiddenEntryField = drnFindByID(idHiddenEntryField);

    jqPicker.datetimepicker( {
                                 format: format,
                                 stepping: 1,
                                 collapse: false,
                                 locale: locale,
                                 showTodayButton: true,
                                 icons: g_dateTimePickerIcons,
                                 tooltips: g_dateTimePickerTooltips
                             } );

    /*  dp.change is fired when the date is changed. Parameters:
            e = {
                date, //date the picker changed to. Type: moment object (clone)
                oldDate //previous date. Type: moment object (clone) or false in the event of a null
            }
     */
    jqPicker.bind('dp.change', (e: any) =>
    {
        let isoDate = '';
        if (    ('date' in e)
             && (e.date)
           )
            isoDate = e.date.format(getFormatString(format));  // 2012-01-02
        jqHiddenEntryField.val(isoDate);
    });

    // Allow minute steps by manual input, but the picker makes 30 minute steps.
    jqPicker.on('dp.show', () => {
        jqPicker.data('DateTimePicker').stepping(30);
    });
    jqPicker.on('dp.hide', () => {
        jqPicker.data('DateTimePicker').stepping(1);
    });

    /* If we have a value, set it in the control. We could use defaultDate in the options
       but then other attached handlers like "required value" might not fire. So use this ugly delay. */
    if (value)
    {
        let format2 = getFormatString(format);
        window.setTimeout( () =>
                       {
                           let m = moment(value, format2);
                           jqPicker.data('DateTimePicker').date(m);
                       });
    }
}

/**
 *   Implementation for HTMLChunk::linkTimePicker(). This links two time pickers together so that the
 // *   second gets updated when the first changes.
 */
export function drnLinkTimePickers(idStart: string,
                            idEnd: string,
                            deltaMinutes: number)       //!< in: no. of minutes to add to time in idStart for idEnd
{
    let jqStartPicker = drnFindByID(idStart + '-div-for-picker');
    let jqEndPicker = drnFindByID(idEnd + '-div-for-picker');

    // Attach a second handler to the "start" bootstrap date/time picker.
    jqStartPicker.bind('dp.change', (e: any) =>
    {
        if (    ('date' in e)
             && (e.date)
           )
        {
            let m = moment(e.date);
            m.add(deltaMinutes, 'minutes');
            jqEndPicker.data('DateTimePicker').date(m);
        }
    });
}
