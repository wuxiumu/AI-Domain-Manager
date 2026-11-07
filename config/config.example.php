<?php
/** Optional legacy defaults. Fresh installations use the browser setup flow. */
return [
    'accounts' => [],
    'http' => ['proxy' => '', 'timeout' => 25, 'connect_timeout' => 10],
    // AI 建议（智谱 GLM）：不配置或留空 api_key 时，计划页的「AI 建议」会提示未配置；models 控制前端可选范围
    'ai' => ['api_key' => '', 'model' => 'glm-4-flash', 'models' => ['glm-4-flash', 'glm-4.5', 'glm-5.3']],
];
