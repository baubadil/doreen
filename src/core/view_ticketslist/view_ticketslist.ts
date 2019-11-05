/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */
import * as vis from 'vis';
import * as $ from 'jquery';

declare global {
    const g_ticketData: any;
}

function initTicketResultsGraph(jsCanvas: HTMLElement)
{
    var pxHeight = $(window).height() - 300;

    var options: vis.Options =
    {
        width: '100%',
        height: pxHeight + 'px',
        groups:
        {
            default:
            {
                shape: 'box',
                color:
                {
                    border: 'blue',
                    background: 'blue',
                    highlight:
                    {
                        border: 'orange',
                        background: 'blue'
                    }
                },
                font: { color: 'white' }
            }
        },
        physics:
        {
            barnesHut:
            {
                gravitationalConstant: -2000
                        // default: -2000
                        // Gravity attracts. We like repulsion. So the value is negative. If you want the repulsion to be stronger, decrease the value (so -10000, -50000).
              , centralGravity: 0.3
                        // default: 0.3
                        // There is a central gravity attractor to pull the entire network back to the center.
              , springLength: 100
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
    };
    g_globals.visjs = new vis.Network(jsCanvas,
                                      g_ticketData, // generated by PHP
                                      options);
//     g_globals.visjs.on('doubleClick', function(fields)
//     {
//         if (fields.nodes.length == 1)
//         {
//             var node = fields.nodes[0];
//             if (match = node.match(/template_([0-9-]+)/))
//                 runEditTemplateDialog(match[1]);
//             if (match = node.match(/type_([0-9-]+)/))
//                 runEditTypeDialog(match[1]);
//         }
//     });
}

export function onTicketResultsGraphDocumentReady()
{
    var jsCanvas = document.getElementById('graph-canvas');
    if (jsCanvas)
        initTicketResultsGraph(jsCanvas);
}

export function onTicketTableReady()
{
    $('#filtersbutton').on('click', function(e)
    {
        var jqButton = $(e.target);
        var jq = $('.table-drill-down');
        if (jq.hasClass('hidden-xs'))
        {
            jq.removeClass('hidden-xs');
            jqButton.attr('aria-pressed', 'true');
            jqButton.addClass('active');
        }
        else
        {
            jq.addClass('hidden-xs');
            jqButton.attr('aria-pressed', 'false');
            jqButton.removeClass('active');
        }
    });
}
