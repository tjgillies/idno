<?php

    namespace IdnoPlugins\Like {

        class ContentType extends \Idno\Common\ContentType {

            public $title = 'Link';
            public $category_title = 'Bookmarked pages';
            public $entity_class = 'IdnoPlugins\\Like\\Like';
            public $indieWebContentType = ['like'];

        }

    }