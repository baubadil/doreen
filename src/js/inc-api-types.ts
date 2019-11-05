/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

/**
 *  Generic interface that all actual JSON API result declarations should extend.
 */
export interface ApiResult
{
    status: string;         // 'OK' unless error
    message?: string;        // if status == 'error'
}


/********************************************************************
 *
 *  Progress
 *
 ********************************************************************/

/**
 *  Generic progress data shared between several progress APIs.
 */
export interface ProgressData
{
    cCurrent: number;
    cTotal: number;
    cCurrentFormatted: string;
    cTotalFormatted: string;

    secondsPassed: number;
    secondsRemaining: number;
    timeRemaining?: string;         // not present if fDone == true

    fDone: boolean;
}

/**
 *  Response data returned by the GET /progress REST API.
 */
export interface ProgressDataResult extends ApiResult, ProgressData
{
}

/**
 *  Response data returned by the GET /listen REST API.
 */
export interface ListenResult extends ApiResult
{
    event: string;      // 'timeout', 'started', 'progress', 'error'
    idSession?: number;  // present with 'started', 'progress', 'error'
}

/**
 *  Response data returned by the GET /listen REST API when event == 'progress'.
 */
export interface ListenResultProgress extends ListenResult
{
    data: ProgressData;
}

export interface ListenResultError extends ListenResult
{
    data: {
        code: number;
        command?: string;
    }
}


/********************************************************************
 *
 *  Users
 *
 ********************************************************************/

export interface IUser
{
    uid: number,
    login: string,
    longname: string,
    email: string,
    fTicketMail: boolean,
    groups: string,
    fAdmin: boolean,
    fGuru: boolean,
    fMayEdit: boolean,
    fCanLogin: boolean,
    fIsLoggedIn: boolean,
    fCanImpersonate: boolean
}

/**
 *  Response data returned by the GET /users REST API.
 */
export interface GetUsersApiResult extends ApiResult
{
    results: IUser[];
    uidGuest: number;
}

/**
 *  Response data returned by the POST or PUT /user REST API.
 */
export interface PostOrPutUserApiResult extends ApiResult
{
    result: IUser;
}


/********************************************************************
 *
 *  Groups
 *
 ********************************************************************/

export interface IGroup
{
    gid: number;
    gname: string;
    members: string;        // "-1",
    cUsedInACLs: number;
}

/**
 *  Response data returned by the GET /groups REST API.
 */
export interface GetGroupsApiResult extends ApiResult
{
    results: IGroup[];
    gidAllUsers: number;
    gidAdmins: number;
    gidGurus: number;
    gidEditors: number;
}

/**
 *  Response data returned by the POST or PUT /group REST API.
 */
export interface PostOrPutGroupApiResult extends ApiResult
{
    result: IGroup;
}


/********************************************************************
 *
 *  Tickets
 *
 ********************************************************************/

/**
 *  Core data that is present in all ticket data returned by the Doreen core,
 *  for example by the GET /tickets REST API.
 *
 *  This is the minimal field set for all tickets regardless of types; plugins
 *  often derive more specific interfaces from this for their specialized ticket
 *  types.
 */
export interface ITicketCore
{
    // Basic fields, present in all tickets.
    ticket_id: number;
    type_id: number;
    type: string;
    icon: string;
    href: string;               // Short link to to to ticket details
    hrefEdit?: string;          // Short link go to ticket editor directly; only present if user has update permission
    createdUTC: string;         // Timestamp
    createdDate: string;
    created_formatted: string;
    createdByUID: number;
    createdByUserName: string;
    htmlLongerTitle: string;    // Fully formatted ticket title with link; this is used in the grid panel per ticket.
    nlsFlyOver: string;         // Description of the ticket link in href
    nlsFlyOverEdit?: string;    // Description of the ticket link in hrefEdit; only present if user has update permission

    format_grid: string;        // only when formatting is requested
}

export interface TicketTitleData
{
    // FIELD_TITLE
    title: string;
}

export interface TicketStatusData
{
    // FIELD_STATUS
    status: number;
    status_formatted: string;
}

export interface FindResultsFilter
{
    id: number;
    name: string;
    name_formatted: string;
    html: string;
    multiple: boolean;
}

export enum SortDirection
{
    ASCENDING = 0,
    DESCENDING = 1
}

export enum SortType
{
    TEXT = 'text',
    NUMBER = 'num'
}

export interface SortBy
{
    param: string;                  // Ticket field name to be used in API request 'sortby' param.
    name: string;                   // Human-readable ticket field description.
    direction: SortDirection;       // Default sorting direction
    type: SortType;                 // Sort value type
}

export interface TicketListFormat
{
    format: string;                 // Ticket list format identifier (e.g. 'grid' or 'list')
    hover: string;                  // For mouse fly-over information over the button
    htmlIcon: string;               // HTML to display as htmlIcon
}

/**
 *  Response data returned by the GET /tickets REST API and others. This format
 *  is expected by AjaxTicketsTable. The \ref Ticket::MakeApiResult()
 *  method in the PHP backend generates data in this format.
 */
export interface GetTicketsApiResult extends ApiResult
{
    cTotal: number;

    cTotalFormatted?: string;       // Only present if cTotal > 0.
    results?: ITicketCore[];        // Only present if cTotal > 0.
    filters?: FindResultsFilter[];  // Only present if cTotal > 0.
    page?: number;                  // Only present if cTotal > 0.
    cPages: number;

    nlsFoundMessage: string;        // Generated by API, e.g. "123 tickets found in total."
    htmlPagination: string;

    sortby: string;                 // Current sort criterion. This is a ticket field name and can be prefixed with "!".
    aSortbys: SortBy[];             // List of supported sort criteria; only present if cTotal > 1.

    format: string;                 // Current format, if specified (e.g. 'grid' or 'list').
    aFormats: TicketListFormat[];   // List of supported ticket list formats.

    llHighlights: string[];
}

export interface ITicketField
{
    id: number;
    name: string;
    tblname: string;
    fDetailsOnly: boolean;
}

export interface ITicketType
{
    id: number;
    name: string;
    parent_type_id: number;
    workflow_id: number | null,
    details_fields: string; // "-1,-2,-13,-14,-16,-6,-9,-4,-3,-34,-33",
    list_fields: string; // "-1,-6,-9,-4,-3,-34,-33",
    cUsage: number,
    cUsageFormatted: string;
}

export interface IMyTemplate
{
    id: number;
    title: string;
    htmlTitle: string;
    href: string;
}

/**
 *  Response data returned by the GET /templates REST API.
 */
export interface GetTemplatesApiResult extends ApiResult
{
    results: IMyTemplate[];
}

export interface ITemplate
{
    ticket_id: number;
    template: string;       // template name
    type_id: number;
    access: string[];
    access_formatted: string;
    usage: number;
    usage_formatted: string;
}

/**
 *  Response data returned by the GET /all-templates REST API.
 */
export interface GetAllTemplatesApiResult extends ApiResult
{
    results: ITemplate[];
}

/**
 *  Response data returned by the GET /tickettypes REST API.
 */
export interface GetTicketTypesApiResult extends ApiResult
{
    results: ITicketType[];
}

export interface StateTransition
{
    [from: number]: number[];           // from => [ to, to, to ]
}

export interface ITicketWorkflow
{
    id: number;
    name: string;
    initial: number;
    statuses: string;           // comma-separated list of integer values
    aTransitions: StateTransition[] | null;
}

export interface IStatus
{
    status: number;
    name: string;
    color: string;
}

export interface Comment
{
    comment: string;
    comment_id: number;
}
