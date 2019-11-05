/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */
import EntryPoint from './entrypoint';
import * as $ from 'jquery';
// Modules that we want to load for side-effects only.
import '../sessionerror';
import 'bootstrap';

interface EntryPoints
{
    [component: string]: EntryPoint
}

interface SerializedAction {
    onReady: boolean;
    component: 'core' | string;
    action: string;
    args: any[];
}

declare global
{
    interface Window
    {
        drnEntryPoint: MainEntry;
    }
}

/**
 *  Handler that lives on drnEntryPoint. Individual plugins and other entry point
 *  providers register themselves with a EntryPoint interface on this. Those
 *  registered instances are called components.
 *
 *  The instance on window.drnEntryPoint is shared between module entry points.
 */
class MainEntry
{
    private components: EntryPoints = {};

    /**
     *  Looks for the given component and executes the action on it.
     *
     *  Currently implements the "register" action for the "core" component,
     *  which is used in PHP when JS actions are registered. It calls the
     *  actions defined in an array with the given parameters.
     */
    action(component: 'system' | 'core' | string,
           action: string,
           ...args)
    {
        if (component === 'system')
        {
            const actions: SerializedAction[] = args[0];
            const readyActions = [];
            const isReady = document.readyState != "loading";
            for (const action of actions) {
                if (action.onReady && !isReady)
                    readyActions.push(action);
                else
                    this.action(action.component, action.action, ...action.args);
            }
            if (readyActions.length && !isReady) {
                $(document).ready(() => {
                    for (const action of readyActions) {
                        this.action(action.component, action.action, ...action.args);
                    }
                });
            }
        }
        else if (this.components.hasOwnProperty(component)) {
            this.components[component].action(action, ...args);
        }
        else {
            console.warn("Could not find component", component);
        }
    }

    registerEntryPoint(component: string, entry: EntryPoint)
    {
        this.components[component] = entry;
    }
}

// Side effect: singleton shared between module silos.
if (!window.drnEntryPoint)
    window.drnEntryPoint = new MainEntry();

export default window.drnEntryPoint;
