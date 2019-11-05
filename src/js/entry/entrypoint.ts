/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

/**
 *  Interface for a component to register with the drnEntryPoint manager.
 *  Whenever an action on the component is called, the request is passed on to
 *  the action method.
 */
export default interface EntryPoint
{
    /**
     *  Gets called when this component is asked to handle an action. Takes any
     *  amount of arguments and returns nothing for now.
     *
     *  If this ever were to return something it should be a promise, so it
     *  could dynamically load in the actual action.
     */
    action(action: string, ...args): void;
    // registerAction(action: string, handler: (...args) => void);
}
