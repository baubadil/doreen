/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

declare global
{
    interface Window {
        g_sessionManager: typeof SessionManager
    }
}

interface JWTChangeHandlers
{
    [source: string]: () => void;
}

class SessionManager
{
    private static lastRefresh: number;
    private static sessionLength: number;
    private static changeHandlers: JWTChangeHandlers = {};
    private static errorHandlers: (() => void)[] = [];

    /**
     *  Let the front end handle the token changing.
     */
    public static onJWTChange(source: string)
    {
        for (const handlerSource in this.changeHandlers)
            if (this.changeHandlers.hasOwnProperty(handlerSource))
                {
                    if (handlerSource != source)
                        this.changeHandlers[handlerSource]();
                }
    }

    /**
     *  Register a handler callback to adjust when the token changes.
     */
    public static registerJWTChangeHandler(source: string, handler: () => void)
    {
        this.changeHandlers[source] = handler;
    }

    /**
     *  Ensures the current session stays alive and replaces the current token.
     */
    private static refreshJWT(onRefresh: () => void)
    {
        $.get(g_rootpage + '/keep-alive', (data: Object, textStatus: string, jqXHR) => {
            this.onJWTChange('sessionManagerRefresh');
            onRefresh();
        });
    }

    /*
     *  Sends a request just before the session would expire, to ensure it is not garbage collected.
     *  The sessionLength parameter holds the maximum life time of a session in seconds.
     */
    public static keepSessionAlive(sessionLength: number)
    {
        this.sessionLength = sessionLength * 1000;
        const refreshBuffer = sessionLength > 60 ? 60 : 1; //s
        const refreshInterval = (sessionLength - refreshBuffer) * 1000; //ms

        if (refreshInterval < 1000)
        {
            console.warn("Will not try to keep session alive, since session doesn't live long enough");
            return;
        }

        this.lastRefresh = Date.now();
        this.registerJWTChangeHandler('sessionManagerKeepAlive', () => {
            this.lastRefresh = Date.now();
        });

        const waitForSessionToExpire = () => {
            setTimeout(() => this.refreshJWT(waitForSessionToExpire), refreshInterval);
        };
        waitForSessionToExpire();

        // Check if the token is still valid when the user returns to the tab.
        window.addEventListener("focus", () => {
            if (this.isSessionExpired())
                this.onSessionError();
        });
    };

    /**
     *  Check if the current client may still have a valid session.
     */
    public static isSessionExpired()
    {
        if (!this.sessionLength)
            return false;
        return this.lastRefresh + this.sessionLength < Date.now();
    }

    /**
     *  Register a handler for when the client session has ended.
     */
    public static registerSessionErrorHandler(handler: () => void)
    {
        this.errorHandlers.push(handler);
    }

    /**
     *  Dispatch the end of the session to the handlers.
     */
    public static onSessionError()
    {
        // Fallback if no handlers have been registered.
        if (!this.errorHandlers.length)
            alert("Your session has expired. Please reload.");
        else
        {
            for (const handler of this.errorHandlers)
                handler();
        }
    }
}

// Singleton pattern because it gets duplicated accross TS bundles :(
if (!window.g_sessionManager)
    window.g_sessionManager = SessionManager;

export default window.g_sessionManager;
