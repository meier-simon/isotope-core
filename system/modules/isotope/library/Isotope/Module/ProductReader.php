<?php

/*
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009 - 2019 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Module;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\Database;
use Contao\Environment;
use Contao\PageModel;
use Contao\StringUtil;
use Haste\Http\Response\HtmlResponse;
use Haste\Input\Input;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Model\Product;
use Isotope\Model\Product\AbstractProduct;

/**
 * Class ProductReader
 *
 * @property bool   $iso_use_quantity
 * @property bool   $iso_display404Page
 * @property bool   $iso_addProductJumpTo
 * @property string $iso_reader_layout
 * @property int    $iso_gallery
 */
class ProductReader extends Module
{
    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'mod_iso_productreader';

    /**
     * Product
     * @var IsotopeProduct
     */
    protected $objProduct;


    /**
     * Display a wildcard in the back end
     * @return string
     */
    public function generate()
    {
        if ('BE' === TL_MODE) {
            return $this->generateWildcard();
        }

        // Return if no product has been specified
        if (Input::getAutoItem('product', false, true) == '') {
            if ($this->iso_display404Page) {
                throw new PageNotFoundException();
            }

            return '';
        }

        return parent::generate();
    }


    /**
     * Generate module
     * @return void
     */
    protected function compile()
    {
        $jumpTo = $GLOBALS['objIsotopeListPage'] ?: $GLOBALS['objPage'];

        if ($jumpTo->iso_readerMode === 'none') {
            throw new PageNotFoundException();
        }

        /** @var AbstractProduct $objProduct */
        $objProduct = Product::findAvailableByIdOrAlias(Input::getAutoItem('product'));

        if (null === $objProduct) {
            throw new PageNotFoundException();
        }

        $arrConfig = array(
            'module'      => $this,
            'template'    => $this->iso_reader_layout ? : $objProduct->getType()->reader_template,
            'gallery'     => $this->iso_gallery ? : $objProduct->getType()->reader_gallery,
            'buttons'     => $this->iso_buttons,
            'useQuantity' => $this->iso_use_quantity,
            'disableOptions' => $this->iso_disable_options,
            'jumpTo'      => $jumpTo,
        );

        if (Environment::get('isAjaxRequest')
            && Input::post('AJAX_MODULE') == $this->id
            && Input::post('AJAX_PRODUCT') == $objProduct->getProductId()
            && !$this->iso_disable_options
        ) {
            try {
                $objResponse = new HtmlResponse($objProduct->generate($arrConfig));
                $objResponse->send();
            } catch (\InvalidArgumentException $e) {
                return;
            }
        }

        $this->addMetaTags($objProduct);
        $this->addCanonicalProductUrls($objProduct);

        $this->Template->product       = $objProduct->generate($arrConfig);
        $this->Template->product_id    = $objProduct->getCssId();
        $this->Template->product_class = $objProduct->getCssClass();
        $this->Template->referer       = 'javascript:history.go(-1)';
        $this->Template->back          = $GLOBALS['TL_LANG']['MSC']['goBack'];
    }

    /**
     * Add meta header fields to the current page
     *
     * @param Product $objProduct
     */
    protected function addMetaTags(Product $objProduct)
    {
        global $objPage;

        $descriptionFallback = ($objProduct->teaser ?: $objProduct->description);

        $objPage->pageTitle   = $this->prepareMetaDescription($objProduct->meta_title ?: $objProduct->getName());
        $objPage->description = $this->prepareMetaDescription($objProduct->meta_description ?: $descriptionFallback);

        if ($objProduct->meta_keywords) {
            $GLOBALS['TL_KEYWORDS'] .= ($GLOBALS['TL_KEYWORDS'] != '' ? ', ' : '') . $objProduct->meta_keywords;
        }
    }

    /**
     * Adds canonical product URLs to the document
     *
     * @param Product $objProduct
     */
    protected function addCanonicalProductUrls(Product $objProduct)
    {
        global $objPage;
        $arrPageIds   = Database::getInstance()->getChildRecords($objPage->rootId, PageModel::getTable());
        $arrPageIds[] = $objPage->rootId;

        // Find the categories in the current root
        $arrCategories = array_intersect($objProduct->getCategories(), $arrPageIds);

        foreach ($arrCategories as $intPage) {
            if (($objJumpTo = PageModel::findPublishedById($intPage)) !== null) {

                // Do not use the index page as canonical link
                if ('index' === $objJumpTo->alias && \count($arrCategories) > 1) {
                    continue;
                }

                $objJumpTo->loadDetails();
                $strDomain = Environment::get('base');

                // Overwrite the domain
                if ($objJumpTo->dns != '') {
                    $strDomain = ($objJumpTo->useSSL ? 'https://' : 'http://') . $objJumpTo->dns . TL_PATH . '/';
                }

                $href = $strDomain . $objProduct->generateUrl($objJumpTo);
                $GLOBALS['TL_HEAD'][] = '<link rel="canonical" href="' . $href . '">';

                break;
            }
        }
    }

    /**
     * Gets the CSS ID for this product
     *
     * @param Product $objProduct
     *
     * @return string|null
     *
     * @deprecated Use AbstractProduct::getCssId()
     */
    protected function getCssId(Product $objProduct)
    {
        $css = StringUtil::deserialize($objProduct->cssID, true);

        return $css[0] ? ' id="' . $css[0] . '"' : null;
    }

    /**
     * Gets the CSS classes for this product
     *
     * @param Product $objProduct
     *
     * @return string
     *
     * @deprecated Use AbstractProduct::getCssClass()
     */
    protected function getCssClass(Product $objProduct)
    {
        if ($objProduct instanceof AbstractProduct) {
            return $objProduct->getCssClass();
        }

        $classes = ['product'];

        if ($objProduct->isNew()) {
            $classes[] = 'new';
        }

        $arrCSS = StringUtil::deserialize($objProduct->cssID, true);
        if ('' !== (string) $arrCSS[1]) {
            $classes[] = (string) $arrCSS[1];
        }

        return implode(' ', $classes);
    }
}
