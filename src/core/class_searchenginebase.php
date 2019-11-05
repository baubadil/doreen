<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/*
 *  Feature matrix about what we need and who can provide it.
 *
 *   -- Stemming (find "assembling" if user enters "assemble")
 *
 *   -- Ranking / boost (score results, allow boosting if things
 *      are found in the title)
 *
 *   -- Multiple languages
 *
 *   -- Fuzzy search for misspellings
 *
 *   -- Accent support (SELECT unaccent('èéêë'))
 *
 *  Feature matrix:
 *
 *    Feature                | MySQL  | PostgreSQL | Elasticsearch
 *  -------------------------+--------+------------+---------------
 *    Stemming               |   ?    |      +     |       +
 *    Ranking / boost        |   -    |      +     |       +
 *    Multiple languages     |   -    |     some   |     many
 *    Fuzzy search           |   -    |      +     |       +
 *    Accent support         |   -    |      +     |       +
 */

/********************************************************************
 *
 *  SearchBase interface
 *
 ********************************************************************/

/**
 *  SearchBase is an abstract base class defining the interface for search engines.
 *
 *  A Doreen search engine must subclass this and implement its abstract methods
 *  to update the search index when tickets are created / changed / deleted and when
 *  comments or attachments are added.
 *
 *  To implement a search engine
 */
abstract class SearchEngineBase
{
    const NOT_RUNNING = 0;      # Search server is not running at all.
    const STALLED = 1;          # Search server is reported as running but does not respond.
    const POORLY = 2;           # Search server is running but not healthily (e.g. status = orange or red).
    const NOINDEX = 3;          # Search server is running but index has not yet been created. Happens directly after install.
    const RUNNING = 4;          # Search server is running perfectly.

    /**
     *  Must return one of SearchBase::NOT_RUNNING, SearchBase::RUNNING, SearchBase::STALLED.
     */
    abstract public function getStatus($fTestExtended = FALSE);

    /**
     *  Gets called by Ticket::createAnother().
     */
    abstract public function onTicketCreated(Ticket $oTicket);

    /**
     *  Gets called by Ticket::update().
     */
    abstract public function onTicketUpdated(Ticket $oTicket);

    /**
     *  Gets called by Ticket::delete().
     */
    abstract public function onTicketDeleted(Ticket $oTicket);

    /**
     *  Gets called by Ticket::addComment().
     */
    abstract public function onCommentAdded(Ticket $oTicket,
                                            $idComment,
                                            $htmlComment);

    /**
     *  Gets called by Ticket::deleteComment().
     */
    abstract public function onCommentDeleted(Ticket $oTicket,
                                              $idComment);

    /**
     *  Gets called by Ticket::attachFiles().
     */
    abstract public function onAttachmentAdded(Ticket $oTicket,
                                               Binary $oBinary,     //!< in: attachment metadata
                                               $blob = NULL);       //!< in: complete attachment (binary) data, if available, for efficiency; otherwise we load it here

    /**
     *  Gets called by Ticket::FindMany() to get statistics about ticket search results.
     *  This also implements full-text search via the search engine.
     *
     *  This must return a SearchResults instance with the $cTotal, $aTypes, $aDrillDownCounts fields
     *  set, but the $aTickets array left to NULL, which the caller will then fill.
     *  If the cTickets field therein is also set to NULL, then the caller will do an SQL COUNT on the
     *  results set, which is also fairly slow (0.2 seconds for 600K tickets).
     *
     *  Returns Ticket IDs ordered in the given sort order.
     *  @return SearchResults|null
     */
    abstract public function search(array $aHardFilters,
                                    array $aSort,
                                    array $aDrillDownFilters = NULL,
                                    int $page = NULL);

    /**
     *  Gets called by the /suggest-searches API. This must return a flat list of objects, each of which must
     *  have a 'v' key with a suggestion value and an 'id' key with a unique index.
     *
     *  @return array|null
     */
    abstract public function suggestSearches(string $query,
                                             $llAids);        //!< in: ACL ID list as returned by Access:getACLsForAccess() or NULL

    /**
     *  Gets called during installation and by reindexAll() to initialize the search engine,
     *  for example, to give it a chance create a full-text index.
     */
    abstract public function onInstall();

    /**
     *  Called by ServiceBase::AutostartConfigureSystemd() during the "autostart-systemd" CLI command
     *  to give the search engine a chance to add a service to the doreen systemd file.
     *
     * @return void
     */
    abstract public function configureSystemD(string $etcSystemdSystem,
                                              &$aEntries);

    private static function PrepareCli()
    {
        if (!($oSearch = Plugins::GetSearchInstance() ))
            throw new DrnException("No search instance found. Either no search instance is configured, or it's not active.");

        Process::AssertRunningAsApacheSystemUser();

        return $oSearch;
    }

    /**
     *  Implementation for the reindex-all CLI command. This does a complete reindex of all
     *  tickets with the search engine, which can take hours.
     *
     *  This also registeres the current process as a reindexer longtask, which means that
     *  all users will see a message that the search functionality is currently broken.
     */
    public static function ReindexAllCli($throttleSecs, $mode)
    {
        // Display a message to the users while this is running.
        LongTask::RegisterReindexer();

        $oSearch = self::PrepareCli();
        $oSearch->reindexAll($throttleSecs,
                            function($pidEnded, $cCurrent, $cTotal) use($mode)
                            {
                                $percent = ($cTotal) ? Format::Number($cCurrent / $cTotal * 100, 2) : 100;
                                if ($pidEnded)
                                    echo "PID $pidEnded ended, progress: ".Format::Number($cCurrent)." out of ".Format::Number($cTotal)." ($percent%)\n";
                                else
                                    echo "Progress: ".Format::Number($cCurrent)." out of ".Format::Number($cTotal)." ($percent%)\n";
                                $fDone = $cCurrent >= $cTotal;
                                LongTask::$oRunning->channelNotifyProgress( [ 'cCurrent' => $cCurrent,
                                                                              'cTotal' => $cTotal ],
                                                                            $fDone);
                            });

        LongTask::UnregisterReindexer();
    }

    public static function ReindexOneCli($throttleSecs, $mode, $idTicket)
    {
        /* $oSearch = */ self::PrepareCli();
        if (!($oTicket = Ticket::FindOne($idTicket)))
            throw new DrnException("Invalid ticket ID");

        $oTicket->populate(TRUE);
        $oTicket->reindex();
    }

    /**
     *  Reindexes all tickets. Calls Ticket::reindex() on every ticket.
     */
    public function reindexAll($throttleSecs,
                               Callable $fnProgress)
    {
        # Delete the entire index and recreate the mappings.
        echo "Deleting and recreating search index...\n";
        $this->deleteAll();
        $this->onInstall();

        ini_set('memory_limit', '-1');          # no memory limit

        echo "Loading list of all tickets (this may take a while)...\n";
        $cIndexed = 0;
        $cForThrottle = 0;
        $aHardFilters = [ SearchFilter::NonTemplates() ];
        if ($idTitlePageWikiTicket = Blurb::GetTitlePageWikiTicket())
            $aHardFilters[] = SearchFilter::ExcludeTicketIds( [ $idTitlePageWikiTicket ] );

        if ($findResults = Ticket::FindMany($aHardFilters,
                                            NULL))
        {
            $cTotal = count($findResults->aTickets);

            if ($fnProgress)
                $fnProgress(0, 0, $cTotal);

            # Load the global changelog and pass it to reindex().
            $oChangelog = new Changelog(NULL,       # global changelog for all tickets
                                        [ FIELD_COMMENT, FIELD_ATTACHMENT ]);

            CliBase::ForkServer($findResults->aTickets,
                                300,
                                4,
                                function($aTicketsSlice) use($oChangelog)
                                {
                                    /** @var $aTicketsSlice Ticket[] */
                                    Ticket::PopulateMany($aTicketsSlice,
                                                         Ticket::POPULATE_DETAILS);
                                    foreach ($aTicketsSlice as $ticket_id => $oTicket)
                                        $oTicket->reindex($oChangelog);

                                    return 0;
                                },
                                $fnProgress,
                                $throttleSecs);
        }

        if ($fnProgress)
            $fnProgress(0, $cIndexed, $cIndexed);

        GlobalConfig::FlagNeedReindexAll(FALSE);

        return $cIndexed;
    }

    /**
     *  Deletes everything from the search index. Gets called from the 'reset' CLI command.
     */
    abstract public function deleteAll();


}
