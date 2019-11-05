/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import { drnShowAndFadeIn } from './shared';
import APIHandler from "core/inc-apihandler";

/**
 *  TabbedPage is a shared helper class to implement the typical Doreen administration
 *  pages with multiple tabs. This is designed to work with HTMLChunk::openTabbedPage()
 *  in the back-end.
 */
export default class TabbedPage extends APIHandler
{
    protected tabCurrent: string;

    protected aButtonsForTab: { [ id: string]: JQuery } = {};

    constructor(protected aTabs: string[])
    {
        super();

        // To each #select-$tab's onClick(), attach a closure that calls onTabSelected($tab).
        for (let tab of this.aTabs)
            $("#select-" + tab).click((e) => {
                this.onTabSelected(tab);
                return false;
            });

        $(document).ready(() => {
            let restoredIndex = this.aTabs.indexOf(window.location.hash.substr(1));
            if (restoredIndex === -1)
                restoredIndex = 0;

            this.onTabSelected(this.aTabs[restoredIndex]);
        });

        // Cache the 'create' buttons that should be shown and hidden with each tab.
        for (let tab of this.aTabs)
            this.aButtonsForTab[tab] = $("#create-" + tab);
    }

    /**
     *  Called by the event handler established by the constructor when the user clicks
     *  on a tab in the header. This then activates the corresponding tabbed page. It also
     *  shows and hides create buttons automatically if they are prefixed with "create-"
     *  plus tab ID.
     *
     *  Subclasses may want to override this function if they need to show or hide
     *  additional buttons on the right.
     */
    public onTabSelected(tabNew: string)        //!< in: tab being selected
        : void
    {
        if (tabNew == this.tabCurrent)
            return;

        // first hide the tables that are NOT selected
        for (let tab of this.aTabs)
        {
            let jqCreateButton = this.aButtonsForTab[tab];

            if (tab !== tabNew)
            {
                // Hide all inactive tabs.
                $("#select-" + tab).removeClass("active");
                $("#" + tab).hide();
                if (jqCreateButton.length)
                    jqCreateButton.hide();
            }
            else
                if (jqCreateButton.length)
                    drnShowAndFadeIn(jqCreateButton);
        }

        // now show the one that IS selected
        $("#select-" + tabNew).addClass("active");
        drnShowAndFadeIn($("#" + tabNew));

        this.tabCurrent = tabNew;
    }

}
