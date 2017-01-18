<?php
/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Alchemy\Phrasea\Controller\Prod;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Application\Helper\SearchEngineAware;
use Alchemy\Phrasea\Cache\Exception;
use Alchemy\Phrasea\Controller\Controller;
use Alchemy\Phrasea\Core\Configuration\DisplaySettingService;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryContextFactory;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\Structure;
use Alchemy\Phrasea\SearchEngine\SearchEngineOptions;
use Alchemy\Phrasea\SearchEngine\SearchEngineResult;
use Alchemy\Phrasea\Utilities\StringHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Alchemy\Phrasea\SearchEngine\Elastic\ElasticSearchEngine;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\GlobalStructure;
use Alchemy\Phrasea\Collection\Reference\CollectionReference;

class QueryController extends Controller
{
    use SearchEngineAware;

    public function completion(Request $request)
    {
        $query = (string) $request->request->get('qry');

        // since the query comes from a submited form, normalize crlf,cr,lf ...
        $query = StringHelper::crlfNormalize($query);
        file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) Dt=%.4f, dt=%.4f\n", __FILE__, __LINE__, microtime(true) - (isset($GLOBALS['_t_'])?$GLOBALS['_t_']:($GLOBALS['_t_']=microtime(true))), min((isset($GLOBALS['_t0_'])?microtime(true)-$GLOBALS['_t0_']:0), $GLOBALS['_t0_']=microtime(true)) ), FILE_APPEND);

        $options = SearchEngineOptions::fromRequest($this->app, $request);
        file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) Dt=%.4f, dt=%.4f\n", __FILE__, __LINE__, microtime(true) - (isset($GLOBALS['_t_'])?$GLOBALS['_t_']:($GLOBALS['_t_']=microtime(true))), min((isset($GLOBALS['_t0_'])?microtime(true)-$GLOBALS['_t0_']:0), $GLOBALS['_t0_']=microtime(true)) ), FILE_APPEND);

        //$engine = $this->getSearchEngine();
        //$engine = $this->app['search_engine'];
        $search_engine_structure = GlobalStructure::createFromDataboxes(
            $this->app->getDataboxes(),
            Structure::WITH_EVERYTHING & ~(Structure::STRUCTURE_WITH_FLAGS | Structure::FIELD_WITH_FACETS | Structure::FIELD_WITH_THESAURUS)
        );

        $query_context_factory = new QueryContextFactory(
            $search_engine_structure,
            array_keys($this->app['locales.available']),
            $this->app['locale']
        );

        $engine = new ElasticSearchEngine(
            $this->app,
            $search_engine_structure,
            $this->app['elasticsearch.client'],
            $query_context_factory,
            $this->app['elasticsearch.facets_response.factory'],
            $this->app['elasticsearch.options']
        );


        file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) Dt=%.4f, dt=%.4f\n", __FILE__, __LINE__, microtime(true) - (isset($GLOBALS['_t_'])?$GLOBALS['_t_']:($GLOBALS['_t_']=microtime(true))), min((isset($GLOBALS['_t0_'])?microtime(true)-$GLOBALS['_t0_']:0), $GLOBALS['_t0_']=microtime(true)) ), FILE_APPEND);

        $autocomplete = $engine->autocomplete($query, $options);
        $ret = $autocomplete['text'];

        file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) Dt=%.4f, dt=%.4f\n", __FILE__, __LINE__, microtime(true) - (isset($GLOBALS['_t_'])?$GLOBALS['_t_']:($GLOBALS['_t_']=microtime(true))), min((isset($GLOBALS['_t0_'])?microtime(true)-$GLOBALS['_t0_']:0), $GLOBALS['_t0_']=microtime(true)) ), FILE_APPEND);

        return $this->app->json($ret);
    }

    /**
     * Query Phraseanet to fetch records
     *
     * @param  Request $request
     * @return Response
     */
    public function query(Request $request)
    {
        $query = (string) $request->request->get('qry');

        // since the query comes from a submited form, normalize crlf,cr,lf ...
        $query = StringHelper::crlfNormalize($query);

        $json = array(
            'query' => $query
        );

        $options = SearchEngineOptions::fromRequest($this->app, $request);

        $perPage = (int) $this->getSettings()->getUserSetting($this->getAuthenticatedUser(), 'images_per_page');

        $page = (int) $request->request->get('pag');
        $firstPage = $page < 1;

        $engine = $this->getSearchEngine();
        if ($page < 1) {
            $engine->resetCache();
            $page = 1;
        }

        $options->setFirstResult(($page - 1) * $perPage);
        $options->setMaxResults($perPage);

        $user = $this->getAuthenticatedUser();
        $userManipulator = $this->getUserManipulator();
        $userManipulator->logQuery($user, $query);

        try {
            $result = $engine->query($query, $options);

            if ($this->getSettings()->getUserSetting($user, 'start_page') === 'LAST_QUERY') {
                $userManipulator->setUserSetting($user, 'start_page_query', $query);
            }

            // log array of collectionIds (from $options) for each databox
            $collectionsReferencesByDatabox = $options->getCollectionsReferencesByDatabox();
            foreach ($collectionsReferencesByDatabox as $sbid => $references) {
                $databox = $this->findDataboxById($sbid);
                $collectionsIds = array_map(function(CollectionReference $ref){return $ref->getCollectionId();}, $references);
                $this->getSearchEngineLogger()->log($databox, $result->getUserQuery(), $result->getTotal(), $collectionsIds);
            }

            $proposals = $firstPage ? $result->getProposals() : false;

            $npages = $result->getTotalPages($perPage);

            $page = $result->getCurrentPage($perPage);

            $string = '';

            if ($npages > 1) {
                $d2top = ($npages - $page);
                $d2bottom = $page;

                if (min($d2top, $d2bottom) < 4) {
                    if ($d2bottom < 4) {
                        if($page != 1){
                            $string .= "<a id='PREV_PAGE' class='btn btn-primary btn-mini'></a>";
                        }
                        for ($i = 1; ($i <= 4 && (($i <= $npages) === true)); $i++) {
                            if ($i == $page)
                                $string .= '<input onkeypress="if(event.keyCode == 13 && !isNaN(parseInt(this.value)))gotopage(parseInt(this.value))" type="text" value="' . $i . '" size="' . (strlen((string) $i)) . '" class="btn btn-mini" />';
                            else
                                $string .= "<a onclick='gotopage(" . $i . ");return false;' class='btn btn-primary btn-mini'>" . $i . "</a>";
                        }
                        if ($npages > 4)
                            $string .= "<a id='NEXT_PAGE' class='btn btn-primary btn-mini'></a>";
                        $string .= "<a onclick='gotopage(" . ($npages) . ");return false;' class='btn btn-primary btn-mini' id='last'></a>";
                    } else {
                        $start = $npages - 4;
                        if (($start) > 0){
                            $string .= "<a onclick='gotopage(1);return false;' class='btn btn-primary btn-mini' id='first'></a>";
                            $string .= "<a id='PREV_PAGE' class='btn btn-primary btn-mini'></a>";
                        }else
                            $start = 1;
                        for ($i = ($start); $i <= $npages; $i++) {
                            if ($i == $page)
                                $string .= '<input onkeypress="if(event.keyCode == 13 && !isNaN(parseInt(this.value)))gotopage(parseInt(this.value))" type="text" value="' . $i . '" size="' . (strlen((string) $i)) . '" class="btn btn-mini" />';
                            else
                                $string .= "<a onclick='gotopage(" . $i . ");return false;' class='btn btn-primary btn-mini'>" . $i . "</a>";
                        }
                        if($page < $npages){
                            $string .= "<a id='NEXT_PAGE' class='btn btn-primary btn-mini'></a>";
                        }
                    }
                } else {
                    $string .= "<a onclick='gotopage(1);return false;' class='btn btn-primary btn-mini' id='first'></a>";

                    for ($i = ($page - 2); $i <= ($page + 2); $i++) {
                        if ($i == $page)
                            $string .= '<input onkeypress="if(event.keyCode == 13 && !isNaN(parseInt(this.value)))gotopage(parseInt(this.value))" type="text" value="' . $i . '" size="' . (strlen((string) $i)) . '" class="btn btn-mini" />';
                        else
                            $string .= "<a onclick='gotopage(" . $i . ");return false;' class='btn btn-primary btn-mini'>" . $i . "</a>";
                    }

                    $string .= "<a onclick='gotopage(" . ($npages) . ");return false;' class='btn btn-primary btn-mini' id='last'></a>";
                }
            }
            $string .= '<div style="display:none;"><div id="NEXT_PAGE"></div><div id="PREV_PAGE"></div></div>';

            $explain = "<div id=\"explainResults\" class=\"myexplain\">";

            $explain .= "<img src=\"/assets/common/images/icons/answers.gif\" /><span><b>";

            if ($result->getTotal() != $result->getAvailable()) {
                $explain .= $this->app->trans('reponses:: %available% Resultats rappatries sur un total de %total% trouves', ['available' => $result->getAvailable(), '%total%' => $result->getTotal()]);
            } else {
                $explain .= $this->app->trans('reponses:: %total% Resultats', ['%total%' => $result->getTotal()]);
            }

            $explain .= " </b></span>";
            $explain .= '<br><div>' . ($result->getDuration() / 1000) . ' s</div>dans index ' . $result->getIndexes();
            $explain .= "</div>";

            $infoResult = '<div id="docInfo">'
                . $this->app->trans('%number% documents<br/>selectionnes', ['%number%' => '<span id="nbrecsel"></span>'])
                . '</div><a href="#" class="infoDialog" infos="' . str_replace('"', '&quot;', $explain) . '">'
                . $this->app->trans('%total% reponses', ['%total%' => '<span>'.$result->getTotal().'</span>']) . '</a>';

            $json['infos'] = $infoResult;
            $json['navigationTpl'] = $string;
            $json['navigation'] = [
                'page' => $page,
                'perPage' => $perPage
            ];

            $prop = null;

            if ($firstPage) {
                $propals = $result->getSuggestions();
                if (count($propals) > 0) {
                    foreach ($propals as $prop_array) {
                        if ($prop_array->getSuggestion() !== $query && $prop_array->getHits() > $result->getTotal()) {
                            $prop = $prop_array->getSuggestion();
                            break;
                        }
                    }
                }
            }

            if ($result->getTotal() === 0) {
                $template = 'prod/results/help.html.twig';
            } else {
                $template = 'prod/results/records.html.twig';
            }

            $json['results'] = $this->render($template, ['results'=> $result]);

            /** Debug */
            $json['parsed_query'] = $result->getEngineQuery();
            /** End debug */

            $fieldLabels = [
                'Base_Name' => $this->app->trans('prod::facet:base_label'),
                'Collection_Name' => $this->app->trans('prod::facet:collection_label'),
                'Type_Name' => $this->app->trans('prod::facet:doctype_label'),
            ];
            foreach ($this->app->getDataboxes() as $databox) {
                file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) databox %s\n", __FILE__, __LINE__, var_export($databox->get_dbname(), true)), FILE_APPEND);
                foreach ($databox->get_meta_structure() as $field) {
                    if (!isset($fieldLabels[$field->get_name()])) {
                        file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) field %s\n", __FILE__, __LINE__, var_export($field->get_name(), true)), FILE_APPEND);
                        $fieldLabels[$field->get_name()] = $field->get_label($this->app['locale']);
                    }
                }
            }

            $facets = [];

            foreach ($result->getFacets() as $facet) {
                $facetName = $facet['name'];

                $facet['label'] = isset($fieldLabels[$facetName]) ? $fieldLabels[$facetName] : $facetName;

                $facets[] = $facet;
            }

            $json['facets'] = $facets;
            $json['phrasea_props'] = $proposals;
            $json['total_answers'] = (int) $result->getAvailable();
            $json['next_page'] = ($page < $npages && $result->getAvailable() > 0) ? ($page + 1) : false;
            $json['prev_page'] = ($page > 1 && $result->getAvailable() > 0) ? ($page - 1) : false;
            $json['form'] = $options->serialize();
        }
        catch(\Exception $e) {
            // we'd like a message from the parser so get all the exceptions messages
            $msg = '';
            for(; $e; $e=$e->getPrevious()) {
                $msg .= ($msg ? "\n":"") . $e->getMessage();
            }
            $template = 'prod/results/help.html.twig';
            $result = array(
                'error' => $msg
            );
            $json['results'] = $this->render($template, ['results'=> $result]);
        }


        return $this->app->json($json);
    }

    /**
     * Get a preview answer train
     *
     * @param  Request $request
     * @return Response
     */
    public function queryAnswerTrain(Request $request)
    {
        if (null === $optionsSerial = $request->get('options_serial')) {
            $this->app->abort(400, 'Search engine options are missing');
        }

        try {
            $options = SearchEngineOptions::hydrate($this->app, $optionsSerial);
        } catch (\Exception $e) {
            $this->app->abort(400, 'Provided search engine options are not valid');
        }

        $pos = (int) $request->request->get('pos', 0);
        $query = $request->request->get('query', '');

        $record = new \record_preview($this->app, 'RESULT', $pos, '', $this->getSearchEngine(), $query, $options);

        $index = ($pos - 3) < 0 ? 0 : ($pos - 3);
        return $this->app->json([
            'current' => $this->render('prod/preview/result_train.html.twig', [
                'records'  => $record->get_train(),
                'index' => $index,
                'selected' => $pos,
            ])
        ]);
    }

    /**
     * @return DisplaySettingService
     */
    private function getSettings()
    {
        return $this->app['settings'];
    }

    /**
     * @return mixed
     */
    private function getUserManipulator()
    {
        return $this->app['manipulator.user'];
    }
}
