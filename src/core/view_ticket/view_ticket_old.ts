/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as $ from 'jquery';
import * as vis from 'vis';
import {
    wordWrap,
    makeVisJSColor,
    drnGetLinkColor,
    drnInitGraphOptions,
    isInteger,
} from 'core/shared';
import AjaxModal from 'core/inc-ajaxmodal';
import getIcon from 'core/icon';

declare let g_oGraphColors: any;

function makeNodeTitle(str)
{
    return wordWrap(str, 20);
}

interface Ticket {
    id: number;
    title: string;
    nlsFlyOver: string;
    statusColor: string;
}

export interface ParentTicket extends Ticket
{
    cParents: number;
}

export interface ChildTicket extends Ticket
{
    cChildren: number;
}

/**
 *  Gets called from a document.ready() with the given arrays for parents and children,
 *  to create a VisJS graph from that data.
 *
 *  oConfig must have the following fields:
 *
 *   --  nlsBlocks: NLS string for "blocks"
 *
 *   --  nlsCurrentTicketFlyOver
 *
 *   --  edgeColor: color to use for edges
 *
 *   --  height (int): height of the canvas in pixels
 *
 *   --  distanceToParent (int)
 *
 *   --  distanceToSibling (int)
 */
export function drnInitHierarchyGraph(idCanvas: string,       //!< in: HTML ID of canvas DIV (without '#')
                                      aParents: ParentTicket[],       //!< in: array of parent tickets (each with 'id' and 'title' fields)
                                      aChildren: ChildTicket[],      //!< in: array of child tickets (each with 'id' and 'title' fields)
                                      oConfig: any)
{
    const aNodes = [];
    const aEdges = [];

    // We just copy from the CSS of the bug status field.
    const jqBugstatusSpan = $('#span-bugstatus');

    const clr = (jqBugstatusSpan.length)
                ? jqBugstatusSpan.css("background-color")
                : oConfig.defaultColor;
    // One node for the ticket being displayed.
    const node =  { id: g_globals.ticketID
                , label: g_globals.ticketTitle
                , group: 'this'
                , shape: 'star'
                , title: oConfig.nlsCurrentTicketFlyOver
                , color: makeVisJSColor(clr, clr)       // hover color same as plain color since clicking on it does nothing
                , font: { color: 'black' }
                };

    var hoverColor = drnGetLinkColor();

    aNodes.push( node );

    for (const row of aParents)
    {
        const node = {  id: row.id
                    , label: makeNodeTitle('#' + row.id + ': ' + row.title)
                    , group: 'parents'
                    , title: row.nlsFlyOver
                    , color: makeVisJSColor(row.statusColor, hoverColor),
                   };
        aNodes.push( node );

        aEdges.push( {  from: g_globals.ticketID
                      , to: row.id
                      , label: oConfig.nlsBlocks
                      , color: oConfig.edgeColor
                     }
                   );

        if (row.cParents)
        {
            var node2 = {  id: 'parents-of-' + row.id
                        , label: '+' + row.cParents
                        , group: 'parents'
                        , color: 'gray'
                       };
            aNodes.push( node2 );
            aEdges.push( {  from: row.id
                          , to: 'parents-of-' + row.id
                          , label: oConfig.nlsBlocks
                          , color: oConfig.edgeColor
                         }
                       );
        }
    }
    for (const row of aChildren)
    {
        const node = {  id: row.id
                    , label: makeNodeTitle('#' + row.id + ': ' + row.title)
                    , group: 'children'
                    , title: row.nlsFlyOver
                    , color: makeVisJSColor(row.statusColor, hoverColor)
                   };
        aNodes.push( node );

        aEdges.push( {  from: row.id
                    , to: g_globals.ticketID
                    , label: oConfig.nlsBlocks
                    , color: oConfig.edgeColor
        });

        if (row.cChildren)
        {
            var node2 = {  id: 'children-of-' + row.id
                        , label: '+' + row.cChildren
                        , group: 'children'
                        , color: 'gray'
                       };
            aNodes.push( node2 );
            aEdges.push( {  from: 'children-of-' + row.id
                          , to: row.id
                          , label: oConfig.nlsBlocks
                          , color: oConfig.edgeColor
                         }
                       );
        }
    }

    var data =
    {
        nodes: aNodes,
        edges: aEdges
    };
    var options: vis.Options =
    {
        width: '100%'
        , height: oConfig.height + 'px'
        //, clickToUse: true
        , groups: {}
        , edges:
        {
            width: 2
            , arrows: { to: true }
            , smooth: { enabled: true, type: 'continuous', roundness: 1 }        // 'continuous' is a lot faster than the default 'dynamic'
        }
        , nodes:
        {
            color: { hover: hoverColor }
        }
        , interaction:
        {
            hover: true
            , zoomView: false
        }
    };

    if (oConfig.layout == 'hierarchical')
    {
        options.layout =
            {
                hierarchical:
                {
                    enabled: true
                    , direction: oConfig.direction
                    , levelSeparation: oConfig.distanceToParent
                    , sortMethod: 'directed' // or 'hubsize'
                }
            };
        options.physics =
            {
                hierarchicalRepulsion:
                {
                    nodeDistance: oConfig.distanceToSibling               // This is the range of influence for the repulsion.
                    , centralGravity: 0.0           // There is a central gravity attractor to pull the entire network back to the center.
                    , springLength:     100 	    // The edges are modelled as springs. This springLength here is the the rest length of the spring.
                    , springConstant: 0.01 	        // This is how 'sturdy' the springs are. Higher values mean stronger springs.
                    , damping: 0.09 	            // Accepted range: [0 .. 1]. The damping factor is how much of the velocity from the previous physics simulation iteration carries over to the next iteration.
                }
            };
    }
    else
    {
        options.physics =
            {
                barnesHut:
                {
                    gravitationalConstant: -2000
                    // default: -2000
                    // Gravity attracts. We like repulsion. So the value is negative. If you want the repulsion to be stronger, decrease the value (so -10000, -50000).
                    , centralGravity: 0.3
                    // default: 0.3
                    // There is a central gravity attractor to pull the entire network back to the center.
                    , springLength: 200
                    // default: 95
                    // The edges are modelled as springs. This springLength here is the the rest length of the spring.
                    , springConstant: 0.04
                    // default: 0.04
                    // This is how 'sturdy' the springs are. Higher values mean stronger springs.
                    , damping: 0.5
                    // default: 0.09
                    // Accepted range: [0 .. 1]. The damping factor is how much of the velocity from the previous physics simulation iteration carries over to the next iteration.
                    , avoidOverlap: 0
                    // default: 0
                    // Accepted range: [0 .. 1]. When larger than 0, the size of the node is taken into account.
                    // The distance will be calculated from the radius of the encompassing circle of the node for both the gravity model. Value 1 is maximum overlap avoidance.
                }
                , timestep: 1
            }
    }


    options = drnInitGraphOptions(options,
                                  g_oGraphColors,
                                  [ 'parents', 'this', 'children' ] );

    const domCanvas = document.getElementById(idCanvas);
    g_globals.visjs = new vis.Network(domCanvas,
                                      data,
                                      options);

    g_globals.visjs.on("click", function(params)
    {
        //params.event = "[original event]";
        //document.getElementById('eventSpan').innerHTML = '<h2>Click event:</h2>' + JSON.stringify(params, null, 4);
        if (    (params.hasOwnProperty('nodes'))
             && (params.nodes.length)
           )
        {
            var id = params.nodes[0];
            var match;
            if (    (    (match = /(children|parents)-of-(\d+)$/g.exec(id))
                      && (id = match[2])
                    )
                 || (    (isInteger(id))
                      && (id != g_globals.ticketID)
                    )
               )
                location.assign(g_rootpage + '/ticket/' + id);
        }
    });

    // Ensure that the zoom is at 100% so that fonts look identical. Our canvas size routine
    // ensures that there should be enough space at the top and bottom for things; at most,
    // the extra grandchildren and grandparents on the left and right will be cut off.
    g_globals.visjs.on("stabilized", function(params)
    {
        this.moveTo( { scale: 1.0, animation: false } );
    });

    // I've tried accessing the mouse wheel handler this way but one cannot successfully call
    // removeEventListener without specifying the function and it seems it's a closure inside visjs.
    // So my hack for now is to uncomment the code in vis.js directly instead. Leaving this here for
    // future reference.
    // The following seems to work for both Firefox and Chrome but they use different event names
    // according to http://www.javascriptkit.com/javatutors/onmousewheel.shtml
    //var eventName = (/Firefox/i.test(navigator.userAgent)) ? "DOMMouseScroll" : "mousewheel";
    //
    ////if (domCanvas.removeEventListener)
    //    domCanvas.removeEventListener(eventName, g_globals.visjs.body.eventListeners.onMouseWheel);
}

class MergeAttachmentsDialog extends AjaxModal
{
    constructor(idOlderVersion: string,
                idNewerVersion: string)
    {
        super('mergeFileAttachmentsDialog',
              [],
              `/merge-attachments/${idOlderVersion}/${idNewerVersion}`);
        this.method = 'PUT';
    }

    public
}

function runMergeAttachmentsDialog(idOlderVersion, idNewerVersion)
{
    $('#mergeFileAttachmentsDialog .drn-find-replace-attachments').each(function()
    {
        var htmlLinkOld = $('#row-file-binary-' + idOlderVersion).find("td").eq(2).html();
        var htmlLinkNew = $('#row-file-binary-' + idNewerVersion).find("td").eq(2).html();
        $(this).html($(this).html()
            .replace("%IDOLDERVERSION%", htmlLinkOld)
            .replace("%IDNEWERVERSION%", htmlLinkNew)
            );
    });
    new MergeAttachmentsDialog(idOlderVersion, idNewerVersion);
}

function makeOlderVersionOf(idOlderVersion, idNewerVersion)
{
    runMergeAttachmentsDialog(idOlderVersion, idNewerVersion);
    cancelPickNewerVersionFile();
}

export function onHideAsOlderVersion(binary_id)
{
    cancelPickNewerVersionFile();
    g_globals.pickingVersion = binary_id;

    $('.drn-show-pick-column').each(function(index, row)
    {
        $(this).fadeIn();
        $(this).removeClass('hidden');
    });

    $('.drn-file-pick-row').each(function(index, row)
    {
        var jqThis = $(this);           // has the row now
        if (this.id == 'row-file-binary-' + binary_id)
        {
            jqThis.addClass('danger');
            jqThis.find('td:eq(0)').html("");

            jqThis.popover( { content: 'Please select the newer version that this file should be placed under.', container: 'body', placement: 'top' } );
        }
        else
        {
            var oldId = /row-file-binary-(\d+)/.exec(this.id)[1];
            jqThis.addClass('info');
            jqThis.find('td:first').html('<button class="btn btn-default">' + getIcon('thumbsup') + "</button>").find('button').on('click', function() {
                return makeOlderVersionOf(oldId, binary_id);
            });
        }
    });
}

export function cancelPickNewerVersionFile()
{
    if (g_globals.hasOwnProperty('pickingVersion'))
    {
        var pickingID = g_globals.pickingVersion;

        $('.drn-file-pick-row').removeClass('info');
        $('#row-file-binary-' + pickingID).removeClass('danger');

        $('#drn-file-pick-overlay').remove();

        var hiddenColumns = $('.drn-show-pick-column');
        hiddenColumns.addClass('hidden');

        $('#row-file-binary-' + pickingID).popover('destroy');

        g_globals.pickingVersion = null;
    }
}
