/*
 *  Copyright 2015-18 Baubadil GmbH. All rights reserved.
 */

import { PartsListTable, PartDetails, PartsListEditor} from './ftdb_fieldh_contain';
import { onCategoryClicked } from './ftdb_fieldh_cat';
import {ArticleNosEditor, ArticleNoString} from './ftdb_fieldh_artno';
import { drnFindByID } from 'core/shared';

(<any>window).ftdb_initPartsListTable = (idDiv: string,
                                         idKit: number) =>
{
    new PartsListTable(idDiv, idKit);
};

(<any>window).ftdb_initPartsListEditor = (idPartsListEntryField: string,
                                          idPartsRowsParentDIV: string,
                                          aPartsList: PartDetails[]) =>
{
    new PartsListEditor(idPartsListEntryField,
                        idPartsRowsParentDIV,
                        aPartsList);
};

(<any>window).ftdb_initArticleNosEditor = (idArticleNosEntryField: string,
                                           idArticleRowsParentDIV: string,
                                           aArticleNos: ArticleNoString[]) =>
{
    new ArticleNosEditor(idArticleNosEntryField, idArticleRowsParentDIV, aArticleNos);
};

(<any>window).ftdb_initCategoriesTree = (idDiv: string,
                                         ftExtraIcons: string[]) =>
{
    drnFindByID(idDiv).on('click', '.drn-catlist-plusminus', function(e) {
        e.preventDefault();
        onCategoryClicked($(this), ftExtraIcons);
        return false;
    });
};
