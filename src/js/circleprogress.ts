/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as ProgressBar from 'progressbar.js';
import { drnFindByID, drnIsVisible, drnShowAndFadeIn, dlgShowError } from './shared';

class ServerSession
{
    constructor(private _idSession)
    {
    }

    public getProgress(fn: (fSuccess, json, jq: JQueryXHR) => void)
    {
        let that = this;
        $.ajax(
            {
                type: 'GET',
                url: g_rootpage + '/api/progress/' + that._idSession,
                success: function(json)
                {
                    fn(true, json, null);
                },
                error: function(jqXHR: JQueryXHR)
                {
                    fn(false, jqXHR.responseJSON, jqXHR);
                }
            });
    }
}

export default class CircleProgress
{
    private o;
    private progressInterval = null;
    private oSession: ServerSession = null;
    private readonly jqProgressDIV: JQuery;

    public constructor(private _id: string,
                       color: string)
    {
        this.o = new ProgressBar.Circle('#' + _id,
            {
                strokeWidth: 6,
                easing: 'easeInOut',
                duration: 1400,
                color: color,
                trailColor: '#a0a0a0',
                trailWidth: 4,
                svgStyle: null
            });
        this.jqProgressDIV = drnFindByID(this._id);
    }

    public set(percent: number)
    {
        const prog = percent / 100.0;
        this.o.animate(prog,
                        {
                            duration: 300
                        });
    }

    public show(fShow)
    {
        const fVisible = drnIsVisible(this.jqProgressDIV);

        if (fShow)
        {
            if (!fVisible)
                drnShowAndFadeIn(this.jqProgressDIV);
        }
        else
        {
            if (fVisible)
                this.jqProgressDIV.fadeOut();
        }
    }

    public startJob(idDialog,
                    idSession,
                    oConfig)
    {
        let interval = 500;
        if (oConfig.hasOwnProperty('interval'))
            interval = oConfig.interval;

        if (oConfig.hasOwnProperty('init'))
            oConfig.init();

        this.show(true);

        this.oSession = new ServerSession(idSession);

        let that = this;
        this.progressInterval = window.setInterval(function()
        {
            that.oSession.getProgress(function(fSuccess, json, jqXHR: JQueryXHR)
                                        {
                                            if (!json.fDone)
                                            {
                                                if ('onMoreToDo' in oConfig)
                                                    oConfig.onMoreToDo(json);
                                                that.set(json.progress);
                                            }
                                            else
                                            {
                                                // Finished:
                                                that.set(100);
                                                that.stop();

                                                if (fSuccess)
                                                    oConfig.onFinishedOK(json);
                                            }

                                            if (!fSuccess)
                                                if (jqXHR.status != 404)
                                                    dlgShowError(idDialog, json.message);
                                        });
        }, interval);
    }

    public stop()
    {
        if (this.progressInterval)
            window.clearInterval(this.progressInterval);
        this.progressInterval = null;
    }

}
