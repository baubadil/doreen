<?php

/*
 *  Copyright 2015-17 Baubadil GmbH. All rights reserved.
 */

namespace Doreen;


class FTPartTest extends TestCase
{
    public function __construct()
    {
    }

    public function testCreateTicket()
    {
        $oType = TicketType::FindFromGlobalConfig(PluginFTDB::CONFIGKEY_PART_TYPE_ID, FALSE);
        $this->assert($oType, "Failed to find type ".PluginFTDB::CONFIGKEY_PART_TYPE_ID);

        $idTemplate = GlobalConfig::Get(PluginFTDB::CONFIGKEY_PART_TEMPLATE_ID);
        $this->assert($idTemplate, "Failed to find ft part ticket template ID");

        if (!($oTemplate = Ticket::FindOne($idTemplate)))
            Globals::EchoIfCli("WARNING: cannot find ft part ticket template object, skipping tests");
        else
        {
            $this->assert($oTemplate->template, "Object #$idTemplate is not a template");

            $aAdmins = User::GetActiveAdmins();
            $this->assert($aAdmins, "Cannot find any active administrator user accounts");
            $oUser = array_shift($aAdmins);

            $oFieldFTCat = TicketField::Find(FIELD_FT_CATEGORY_ALL);
            $this->assert($oFieldFTCat, "Cannot find FIELD_FT_CATEGORY_ALL ticket field");

            $strCat = "Gelenkwürfel";
            if (!($oCategory = Category::FindByNameBase($oFieldFTCat, $strCat)))
                Globals::EchoIfCli("WARNING: cannot find ".Format::UTF8Quote($strCat)." FT category, skipping tests");
            else
            {
                $this->assertEquals($oCategory->field_id, FIELD_FT_CATEGORY_ALL);

                $aCatParents = $oCategory->getParents();
                $this->assert(count($aCatParents) == 3,
                              "Expected three items in parents array for category ".Format::UTF8Quote($strCat));

                /*
                 *  This tests the create methods of the given ticket fields.
                 */
                $title = "Test ft part";
                $descr = "Test <b>description</b>";
                $oTestPart = $oTemplate->createAnother($oUser,
                                                       NULL,
                                                       [ 'title' => $title,                             // FieldHandler
                                                         'description' => $descr,    // HTML, but also FieldHandler
                                                         'ft_cat_all' => $oCategory->id,                // Array of ints.
                                                         'ft_weight' => 1.1,                            // FTWeightHandler (ticket_floats)
                                                         'ft_article_nos' => json_encode( [ [ "1989", "12345" ] ] ),
                                                         'ft_variant_uuid' => UUID::Create(),
                                                       ],
                                                       FALSE,
                                                       Ticket::CREATEFL_NOMAIL);

                $this->assertEquals(getArrayItem($oTestPart->aFieldData, FIELD_TITLE), $title);
                $this->assertEquals(getArrayItem($oTestPart->aFieldData, FIELD_DESCRIPTION), $descr);
                $this->assertEquals(getArrayItem($oTestPart->aFieldData, FIELD_FT_CATEGORY_ALL), implode(',', $aCatParents));
                $this->assertEquals(getArrayItem($oTestPart->aFieldData, FIELD_FT_WEIGHT), 1.1);
                $this->assertEquals(getArrayItem($oTestPart->aFieldData, FIELD_FT_ARTICLENOS), "[[\"1989\",\"12345\"]]");

                Globals::EchoIfCli("Created ft part ticket #".$oTestPart->id);

                Ticket::Delete( [ $oTestPart] );

                Globals::EchoIfCli("Deleted ft part ticket #".$oTestPart->id." again");
            }
        }
    }

}

