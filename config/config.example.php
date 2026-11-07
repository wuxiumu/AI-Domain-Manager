<?php
/** Optional legacy defaults. Fresh installations use the browser setup flow. */
return [
    'accounts' => [],
    'http' => ['proxy' => '', 'timeout' => 25, 'connect_timeout' => 10],
    // AI 建议（智谱 GLM / bigmodel.cn）：不配置或留空 api_key 时，计划页的「AI 建议」会提示未配置
    'ai' => ['api_key' => '', 'model' => 'glm-4-flash'],
];
