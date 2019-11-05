/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import { ITicketCore, TicketTitleData } from '../../js/inc-api-types';

/**
 *  Declare an interface with the fields that the FT parts ticket have.
 *  In addition to the core and title, more special fields are available.
 *  We only list the ones we need here.
 */
export interface FTTicketData extends ITicketCore, TicketTitleData
{
    ft_article_nos: any;
    ft_article_nos_formatted: string;
    ft_count: number;
    ft_icon: number;
    ft_icon_formatted: string;
}
