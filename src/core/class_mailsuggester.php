<?php
/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;

class MailSuggester {
    const MAX_SCORE = 1;

    /**
     *  Assign a likeness score to a string based on a query. Always positive.
     *
     *  @return int
     */
    public static function Score(string $item,
                                  string $query)
        : int
    {
        $query = strtolower($query);
        $item = strtolower($item);
        $pos = strpos($item, $query);
        if ($pos !== FALSE)
            return (strlen($item) - $pos) / strlen($item) * self::MAX_SCORE;
        $qpos = strpos($query, $item);
        if ($qpos !== FALSE)
            return (strlen($query) - $qpos) / (2 * strlen($query)) * self::MAX_SCORE;
        return 0;
    }

    /**
     *  Sort array contents by its score and map to { value, data } format. Also
     *  deduplicates values.
     *
     *  @return array
     */
    public static function Sort(array $aSuggestions,
                                 string $query)
        : array
    {
        $aScores = [];
        foreach ($aSuggestions as $v)
        {
            if (!array_key_exists($v['value'], $aScores))
            {
                if(array_key_exists('score', $v) && $v['score'])
                    $aScores[$v['value']] = $v['score'];
                else
                    $aScores[$v['value']] = self::Score($v['value'], $query);
            }
        }
        arsort($aScores);
        $aResults = [];
        $i = 0;
        foreach ($aScores as $v => $score)
        {
            $aResults[] = [
                'value' => $v,
                'data' => $i++
            ];
        }
        return $aResults;
    }

    /**
     *  Gathers mail addresses for a given query. Returns the current user if
     *  they match the query, too. All other results come from plugins with the
     *  CAPSFL_MAILSUGGESTER flag.
     *
     *  Results are formated as { data, value } tuples and ordered by score.
     *
     *  @return array
     */
    public static function SuggestMailAddresses(string $query,
                                                int $max = 10)
        : array
    {
        $aSuggestions = [];

        if ($query[strlen($query) - 1] === '*')
            $query = substr($query, 0, -1);

        if (    LoginSession::IsUserLoggedIn()
             && (    (self::Score(LoginSession::$ouserCurrent->email, $query) > 0)
                  || (self::Score(LoginSession::$ouserCurrent->longname, $query) > 0)))
            $aSuggestions[] = [
                'value' => LoginSession::$ouserCurrent->longname . " <" . LoginSession::$ouserCurrent->email . ">",
            ];

        // If admin: return mails of all accounts?

        $aPlugins = Plugins::GetWithCaps(IPlugin::CAPSFL_MAILSUGGESTER);
        foreach ($aPlugins as $oPlugin)
        {
            $aSuggestions += $oPlugin->suggestMailAddresses($query,
                                                            LoginSession::$ouserCurrent,
                                                            $max);
        }
        $aSortedSuggestions = self::Sort($aSuggestions, $query);
        return array_slice($aSortedSuggestions, 0, $max);
    }
}
