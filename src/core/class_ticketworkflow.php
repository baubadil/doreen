<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


/********************************************************************
 *
 *  TicketWorkflow class
 *
 ********************************************************************/

/**
 *  A TicketWorkflow describes how a ticket's status (FIELD_STATUS) is allowed to change.
 *
 *  Ticket workflows are configured with ticket types; every ticket type that has FIELD_STATUS
 *  visible should have a ticket workflow defined and set.
 *
 *  Ticket workflows allow for having consistent status handling and formatting while
 *  restricting what status values are available depending on the ticket's type.
 *
 *  In management theory, workflows are often called "processes" and can be useful with
 *  business process management. Since a "process" has a different meaning in computing,
 *  the name "workflow" has been chosen for Doreen instead to avoid confusion.
 *
 *  Examples for workflows with different ticket types:
 *
 *   -- If Doreen is used as a bugtracker, there may be a bug ticket type, and a bug can
 *      be "new", "confirmed", "resolved" and finally "closed". (The Doreen bugtracker
 *      plugin is more refined than that, but it illustrates the idea.) The bugtracker
 *      workflow would therefore define and include these status values and also maybe
 *      say that it shouldn't be possible to go from "closed" to "new".
 *
 *   -- For invoice management, every invoice could be a ticket, and it could be "new",
 *      "confirmed", "due", "paid", "booked", "filed".
 *
 *  This way, arbitrary other business processes could be mapped to ticket workflows.
 */
class TicketWorkflow
{
    public $id;                                 # ID. Primary index from workflows table.
    public $name;                               # Workflow descriptive name.
    public $initial;                            # Initial status value for new tickets with this workflow.

    public $workflow_statuses;                  # Comma-separated list of integer status values that this workflow can have.

    public static $aAllLoaded = [];

    private static $fLoadedAll = FALSE;

    private $aTransitions = NULL;               # NULL = not loaded yet; otherwise a two-dimensional array with $from => [ $to, $to, ... ];

    public static $aStatuses = [];              # ID => name pairs.
    private static $aTranslatedStatusNames = NULL; # ID => name pairs, but translated by workflow handlers.
    public static $aStatusCSS = [];             # ID => color pairs.
    public static $fStatusesLoaded = FALSE;


    /********************************************************************
     *
     *  Constructor
     *
     ********************************************************************/

    protected function __construct()
    {
    }

    /**
     *  Returns the TicketWorkflow instance matching the given ID.
     *
     *  If an object has already been instantiated for that ID, it is returned.
     *  Otherwise a new one is created with the given data.
     */
    public static function MakeAwakeOnce(int $id,                   //!< in: workflow ID
                                         string $name,                 //!< in: workflow name (only shown in administrator GUIs)
                                         int $initial,              //!< in: initial status value for new tickets that implement this process
                                         string $workflow_statuses)    //!< in: string with comma-separated list of integer status values (e.g. STATUS_OPEN)
    {
        if (isset(self::$aAllLoaded[$id]))
            return self::$aAllLoaded[$id];

        $o = new self();
        initObject($o,
                   [ 'id', 'name', 'initial', 'workflow_statuses' ],
                   func_get_args());
        self::$aAllLoaded[$id] = $o;
        return $o;
    }


    /********************************************************************
     *
     *  Public instance functions
     *
     ********************************************************************/

    /**
     *  Returns a flat array of values that a ticket state can assume from the given value under the
     *  process that this TicketWorkflow represents. The current value is always included in the array.
     */
    public function getValidStateTransitions(int $current)
    {
        $this->loadTransitions();

        $a = [ $current ];
        if (array_key_exists($current, $this->aTransitions))
            foreach ($this->aTransitions[$current] as $v)
                if ($v != $current)
                    $a[] = $v;
        return $a;
    }

    public function loadTransitions()
    {
        if ($this->aTransitions === NULL)
        {
            # Not loaded yet:
            $this->aTransitions = [];

            $res = Database::DefaultExec('SELECT from_status, to_status FROM state_transitions WHERE workflow_id = $1',
                                                [ $this->id ]);
            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
                $from = $row['from_status'];
                $to = $row['to_status'];
                if (!array_key_exists($from, $this->aTransitions))
                    # First "to" for this "from":
                    $this->aTransitions[$from] = [ $to ];
                else
                    # Another "to" for this "from": then append to secondary array
                    $this->aTransitions[$from][] = $to;
            }
        }
    }

    /**
     *  Returns data for this instance as an array for JSON encoding.
     *
     *  The front-end provides the ITicketWorkflow interface for the result.
     */
    public function toArray()
        : array
    {
        $this->loadTransitions();

        return [ 'id' => (int)$this->id,
                 'name' => $this->name,
                 'initial' => (int)$this->initial,
                 'statuses' => $this->workflow_statuses,
                 'aTransitions' => $this->aTransitions ];
    }

    /**
     *  Updates the status values and valid transitions for this workflow.
     */
    public function update(string $workflow_statuses,    //!< in: string with comma-separated list of integer status values (e.g. STATUS_OPEN))
                           array $aTransitions)         //!< in: complete defintion of the valid state transitions as a two-dimensional array with $from => [ $to, $to, ... ];
    {
        self::SetTransitions($this->id, $workflow_statuses, $aTransitions, TRUE);

        $this->workflow_statuses = $workflow_statuses;
        $this->aTransitions = $aTransitions;
    }


    /********************************************************************
     *
     *  Public static functions
     *
     ********************************************************************/

    /**
     *  Loads the given workflow from the database and returns it, or NULL if not found.
     *
     * @param $workflow_id
     * @param bool $fRequired
     * @return TicketWorkflow|null
     * @throws DrnException
     */
    public static function Find(int $workflow_id,                //!< in: process ID to search for
                                bool $fRequired = FALSE)         //!< in: if TRUE, throw an exception if not found
    {
        self::GetAll();

        if (isset(self::$aAllLoaded[$workflow_id]))
            return self::$aAllLoaded[$workflow_id];

        if ($fRequired)
            throw new DrnException("Cannot find a TicketWorkflow for ID $workflow_id");

        return NULL;
    }

    /**
     *  Returns an array of all the ticket workflows. Loads them from the database on the first call.
     *
     * @return TicketWorkflow[]
     */
    public static function GetAll()
    {
        if (!self::$fLoadedAll)
        {
            $groupConcatProcessStatuses = Database::GetDefault()->makeGroupConcat('workflow_statuses.status_id');
            $res = Database::DefaultExec(<<<SQL
SELECT
    workflows.i AS workflow_id,
    workflows.name AS workflow_name,
    workflows.initial AS workflow_initial,
    (SELECT $groupConcatProcessStatuses FROM workflow_statuses
        WHERE workflow_statuses.workflow_id = workflows.i
    ) AS workflow_statuses
FROM workflows
SQL
                                     );

            while ($row = Database::GetDefault()->fetchNextRow($res))
                self::MakeAwakeOnce($row['workflow_id'],
                                    $row['workflow_name'],
                                    $row['workflow_initial'],
                                    $row['workflow_statuses']);

            self::$fLoadedAll = true;
        }

        return self::$aAllLoaded;
    }

    /**
     *  Like GetAll(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the ITicketWorkflow interface, an array of which is returned here.
     *
     *  Caller must check access permissions before returning any such data!
     */
    public static function GetAllAsArray()
        : array
    {
        $a = self::GetAll();
        $aReturn = [];
        foreach ($a as $id => $o)
            $aReturn[] = $o->toArray();

        return $aReturn;
    }

    /**
     *  Retrieve all possible status valies, regardless of process usage, into a static array.
     *  This is only loaded on the first call.
     */
    public static function GetAllStatusValues()
    {
        if (!self::$fStatusesLoaded)
        {
            $res = Database::DefaultExec(<<<SQL
SELECT
    i,
    name,
    html_color
FROM status_values
SQL
                                     );

            while ($row = Database::GetDefault()->fetchNextRow($res))
            {
                $id = $row['i'];
                self::$aStatuses[$id] = $row['name'];
                self::$aStatusCSS[$id] = $row['html_color'];
            }

            self::$fStatusesLoaded = TRUE;
        }

        return self::$aStatuses;
    }

    /**
     *  Like GetAllStatusValues(), but returns all instances as a PHP array of arrays for JSON encoding.
     *
     *  The front-end provides the IStatus interface, an array of which is returned here.
     */
    public static function GetAllStatusesAsArray()
        : array
    {
        $a = self::GetAllStatusValues();
        $aReturn = [];
        foreach ($a as $status => $name)
        {
            $aReturn[] = [ 'status' => $status,
                           'name' => $name,
                           'color' => self::$aStatusCSS[$status]
                         ];
        }

        return $aReturn;
    }

    /**
     *  Returns the name of the given integer STATUS_* value, or "unknown" if it's not in the list.
     *
     *  The return value is a plain string without HTML formatting.
     *
     *  This calls self::GetAllStatusValues() as needed.
     */
    public static function GetStatusDescription(int $value = NULL)
        : string
    {
        if ($value)
        {
            if (self::$aTranslatedStatusNames === NULL)
            {
                // First call: make a copy, then call all workflow plugins for translations.
                self::GetAllStatusValues();
                self::$aTranslatedStatusNames = self::$aStatuses;

                foreach (Plugins::GetWithCaps(IPlugin::CAPSFL_WORKFLOW) as $oImpl)
                {
                    /** @var IWorkflowPlugin $oImpl  */
                    $oImpl->provideTranslations(self::$aTranslatedStatusNames);
                }
            }

            if (array_key_exists($value, self::$aTranslatedStatusNames))
                return self::$aTranslatedStatusNames[$value];
        }

        return L('{{L//unknown}}');
    }

    /**
     *  Returns the color of the given integer STATUS_* value, or NULL if it's not in the list.
     *
     *  You MUST call self::GetAllStatusValues() beforehand.
     */
    public static function GetStatusColor(int $value)
    {
        return getArrayItem(self::$aStatusCSS, $value);
    }

    /**
     *  Newly introduced static method to help with formatting status color values. This
     *  encloses $htmlStatus in a HTML span with the proper status color as listed in
     *  the database.
     *
     *  You MUST call self::GetAllStatusValues() beforehand.
     */
    public static function StatusFormatHelper(int $value = NULL,               //!< in: STATUS_* integer
                                              string $htmlStatus)          //!< in: status description in HTML (could have additional info from plugin)
        : HTMLChunk
    {
        self::GetAllStatusValues();
        if (!isset(static::$aStatusCSS[$value]))
        {
            $str = ($value === NULL) ? 'NULL' : "invalid status value ".Format::UTF8Quote($value);
            return HTMLChunk::FromString($str);
        }

        $color = self::$aStatusCSS[$value];
        $o = new HTMLChunk();
        $o->html = "<span id=\"span-bugstatus\" class=\"drn-status\" style=\"background-color: $color; color: white;\">$htmlStatus</span>";
        return $o;
    }

    /**
     *  Creates a new status value in the database and returns its integer ID. The status ID
     *  will then be the primary ID from the database; whereas the default status values have
     *  negative IDs, status values created by this function will start with 1.
     */
    public static function CreateStatusValue(string $name,
                                             string $html_color)       //!< in: HTML color to use. Must be a valid HTML color name ('blue') or RGB value ('#808080').
    {
        # Create a new row in status_values and get the ID as the status ID.
        Database::DefaultExec(<<<SQL
INSERT INTO status_values ( name,   html_color ) VALUES
                          ( $1,     $2 )
SQL
                        , [ $name,  $html_color ]);
        $idStatusNew = Database::GetDefault()->getLastInsertID('status_values', 'i');

        self::$aStatuses[$idStatusNew] = $name;
        self::$aStatusCSS[$idStatusNew] = $html_color;

        return $idStatusNew;
    }

    /**
     *  Creates a new workflow in the database and returns it as an object. The workflow ID
     *  will then be the primary ID from the database; whereas the default workflows have
     *  negative IDs, workflows created by this function will start with 1.
     *
     *  The $workflow_statuses and $aTransitions parameters must be complete. Values not
     *  listed there will not be allowed.
     *
     *  Integrity checks are performed by the foreign key constraints in the database so
     *  do not expect invalid values to yield particularly user-friendly error messages.
     */
    public static function Create(string $name,                 //!< in: workflow name (only shown in administrator GUIs)
                                  int $initial,              //!< in: initial status value for new tickets that implement this process
                                  string $workflow_statuses,    //!< in: string with comma-separated list of integer status values (e.g. STATUS_OPEN))
                                  array $aTransitions)         //!< in: complete defintion of the valid state transitions as a two-dimensional array with $from => [ $to, $to, ... ];
    {
        Database::GetDefault()->beginTransaction();

        # Create a new row in workflows and get the ID as the workflow ID.
        Database::DefaultExec(<<<SQL
INSERT INTO workflows ( name,   initial ) VALUES
                      ( $1,     $2 )
SQL
                    , [ $name,  $initial ]);
        $idWorkflowNew = Database::GetDefault()->getLastInsertID('workflows', 'i');

        self::SetTransitions($idWorkflowNew,
                             $workflow_statuses,
                             $aTransitions,
                             FALSE);

        $oNew = self::MakeAwakeOnce($idWorkflowNew,
                                    $name,
                                    $initial,
                                    $workflow_statuses);

        $oNew->aTransitions = $aTransitions;

        Database::GetDefault()->commit();

        return $oNew;
    }

    /**
     *  Overwrites status colors in the \ref status_values table. Used by the theme engine.
     */
    public static function ChangeStatusColors($a)
    {
        foreach ($a as $status => $color)
            Database::DefaultExec('UPDATE status_values SET html_color = $1 where i = $2',
                                  [ $color, $status ]);
    }


    /********************************************************************
     *
     *  Private static functions
     *
     ********************************************************************/

    /**
     *  Private helper used by both \ref Create() and \ref update().
     */
    private static function SetTransitions(int $idWorkflow,
                                           string $workflow_statuses,    //!< in: string with comma-separated list of integer status values (e.g. STATUS_OPEN))
                                           array $aTransitions,         //!< in: complete defintion of the valid state transitions as a two-dimensional array with $from => [ $to, $to, ... ];
                                           bool $fIsUpdate)
    {
        if ($fIsUpdate)
        {
            Database::DefaultExec(<<<SQL
DELETE FROM workflow_statuses WHERE workflow_id = $1
SQL
                                              , [ $idWorkflow ]
            );

            Database::DefaultExec(<<<SQL
DELETE FROM state_transitions WHERE workflow_id = $1
SQL
                                              , [ $idWorkflow ]
            );
        }

        # Now insert pairs into workflow_statuses which values this workflow can have.
        $aValues = [];
        foreach (explode(',', $workflow_statuses) as $status)
        {
            $aValues[] = $idWorkflow;
            $aValues[] = $status;
        }
        Database::GetDefault()->insertMany('workflow_statuses',
                                           [ 'workflow_id', 'status_id' ],
                                           $aValues);

        # Finally, set up the state_transitions table rows.
        $aValues = [];
        foreach ($aTransitions as $statusFrom => $aStatusesTo)
            foreach ($aStatusesTo as $statusTo)
            {
                $aValues[] = $idWorkflow;
                $aValues[] = $statusFrom;
                $aValues[] = $statusTo;
            }

        Database::GetDefault()->insertMany('state_transitions',
                                           [ 'workflow_id', 'from_status', 'to_status' ],
                                           $aValues);
    }

}
