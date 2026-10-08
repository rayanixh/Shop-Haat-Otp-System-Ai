<?php
/**
 * AI Auto Work — centralised prompt engine.
 *
 * Every prompt lives here (or in the ai_prompt_templates table once an admin
 * customises one). Nothing in the rest of the module builds prompt strings
 * inline, so tone/language/SEO behaviour can be changed in one place.
 */

/** Built-in templates. {{placeholders}} are substituted at render time. */
function sh_ai_default_templates(): array
{
    return [
        'product_title' => [
            'label'  => 'Product title',
            'system' => 'You are an expert e-commerce copywriter. Reply with the title text only — '
                      . 'no quotes, no markdown, no explanation.',
            'user'   => "Write one customer-friendly product title.\n\n"
                      . "Current name: {{name}}\nCategory: {{category}}\nBrand: {{brand}}\n"
                      . "Price: {{price}}\nKnown details: {{details}}\n\n"
                      . "Rules:\n- Maximum 70 characters.\n- Plain text, no quotation marks.\n"
                      . "- Do not invent specifications that are not listed above.\n{{seo}}{{tone}}{{language}}",
        ],
        'product_description' => [
            'label'  => 'Product description',
            'system' => 'You are an expert e-commerce copywriter. Return clean HTML using only '
                      . '<p>, <ul>, <li> and <strong>. No markdown, no <html> or <body> wrapper.',
            'user'   => "Write a complete product description.\n\n"
                      . "Product: {{name}}\nCategory: {{category}}\nBrand: {{brand}}\n"
                      . "Price: {{price}}\nType: {{type}}\nExisting description: {{description}}\n"
                      . "Specifications: {{specs}}\n\n"
                      . "Structure:\n- One short opening paragraph.\n- A bulleted list of key benefits.\n"
                      . "- One closing paragraph.\n\n"
                      . "Rules:\n- Never invent specifications, materials, certifications or measurements "
                      . "that are not given above.\n- Aim for 120-220 words.\n{{seo}}{{tone}}{{language}}",
        ],
        'product_short' => [
            'label'  => 'Short description',
            'system' => 'You are an expert e-commerce copywriter. Reply with plain text only.',
            'user'   => "Write a single-sentence short description for product cards and search results.\n\n"
                      . "Product: {{name}}\nCategory: {{category}}\nDetails: {{details}}\n\n"
                      . "Rules:\n- Maximum 160 characters.\n- No quotes, no markdown.\n"
                      . "- Do not invent facts.\n{{tone}}{{language}}",
        ],
        'product_tags' => [
            'label'  => 'Product tags',
            'system' => 'You reply with a JSON array of strings and nothing else. Example: ["one","two"]',
            'user'   => "Suggest 5-8 search tags for this product.\n\n"
                      . "Product: {{name}}\nCategory: {{category}}\nBrand: {{brand}}\nDetails: {{details}}\n\n"
                      . "Rules:\n- Each tag 1-3 words, lowercase unless a brand name.\n"
                      . "- No duplicates, no hashtags.\n- Return ONLY a JSON array.{{language}}",
        ],
        'product_category' => [
            'label'  => 'Category suggestion',
            'system' => 'You reply with JSON only, shaped {"category":"...","confidence":0-100,"reason":"..."}.',
            'user'   => "Choose the single best category for this product from the list.\n\n"
                      . "Product: {{name}}\nDetails: {{details}}\n\n"
                      . "Existing categories:\n{{categories}}\n\n"
                      . "Rules:\n- Prefer an existing category. Use its exact name.\n"
                      . "- Only if nothing fits, suggest a new short category name.\n"
                      . "- Return ONLY the JSON object.",
        ],
        'product_seo' => [
            'label'  => 'Product SEO',
            'system' => 'You reply with JSON only, shaped '
                      . '{"meta_title":"...","meta_description":"...","focus_keyword":"...","keywords":["..."]}.',
            'user'   => "Write SEO metadata for this product.\n\n"
                      . "Product: {{name}}\nCategory: {{category}}\nBrand: {{brand}}\nDetails: {{details}}\n\n"
                      . "Rules:\n- meta_title max 60 characters.\n- meta_description max 155 characters.\n"
                      . "- 4-8 keywords.\n- Return ONLY the JSON object.{{language}}",
        ],
        'category_content' => [
            'label'  => 'Category content',
            'system' => 'You reply with JSON only, shaped '
                      . '{"description":"...","meta_title":"...","meta_description":"...","keywords":["..."]}.',
            'user'   => "Write listing-page content for this shop category.\n\n"
                      . "Category: {{name}}\nExample products: {{products}}\nProduct count: {{count}}\n\n"
                      . "Rules:\n- description: 40-60 words of plain text.\n- meta_title max 60 characters.\n"
                      . "- meta_description max 155 characters.\n- 4-8 keywords.\n"
                      . "- Return ONLY the JSON object.{{seo}}{{tone}}{{language}}",
        ],
        'blog_post' => [
            'label'  => 'Blog post',
            'system' => 'You are a professional blog writer. You reply with JSON only, shaped '
                      . '{"title":"...","excerpt":"...","content":"<h2>..</h2><p>..</p>",'
                      . '"meta_title":"...","meta_description":"...","keywords":["..."]}. '
                      . 'The content field must be clean HTML using only <h2>, <h3>, <p>, <ul>, <li>, <strong>.',
            'user'   => "Write a blog article.\n\n"
                      . "Topic: {{topic}}\nTarget audience: {{audience}}\nKeywords to include: {{keywords}}\n"
                      . "Approximate length: {{length}} words\n\n"
                      . "Structure: an introduction, 3-5 sections with headings, and a conclusion.\n"
                      . "Rules:\n- excerpt max 200 characters.\n- meta_title max 60 characters.\n"
                      . "- meta_description max 155 characters.\n- Return ONLY the JSON object."
                      . "{{seo}}{{tone}}{{language}}",
        ],
        'image_prompt' => [
            'label'  => 'Product image prompt',
            'system' => 'You reply with the image prompt text only — one paragraph, no quotes.',
            'user'   => "Write an image-generation prompt for a professional e-commerce product photo.\n\n"
                      . "Product: {{name}}\nCategory: {{category}}\nDetails: {{details}}\n\n"
                      . "Describe the product, studio lighting, a clean white background, centred "
                      . "composition, realistic texture and commercial product photography style. "
                      . "Do not mention text, logos, watermarks or people.",
        ],
    ];
}

/** Load a template, preferring an admin-customised row over the built-in default. */
function sh_ai_template(string $key): ?array
{
    static $cache = [];
    if (isset($cache[$key])) { return $cache[$key]; }

    $defaults = sh_ai_default_templates();
    $tpl = $defaults[$key] ?? null;

    try {
        $row = sh_one('SELECT system_prompt, user_prompt FROM ai_prompt_templates WHERE template_key = ? LIMIT 1', [$key]);
        if ($row !== null && trim((string)$row['user_prompt']) !== '') {
            $tpl = [
                'label'  => $defaults[$key]['label'] ?? $key,
                'system' => (string)($row['system_prompt'] ?? ''),
                'user'   => (string)$row['user_prompt'],
            ];
        }
    } catch (Throwable $e) { /* fall back to the built-in */ }

    return $cache[$key] = $tpl;
}

/**
 * Render a template with its placeholders replaced.
 * Style directives (tone/language/SEO) come from AI Settings.
 *
 * @return array{system:string,user:string}|null
 */
function sh_ai_render_prompt(string $key, array $vars = []): ?array
{
    $tpl = sh_ai_template($key);
    if ($tpl === null) { return null; }

    $cfg = sh_ai_config();
    $vars['tone']     = $vars['tone']     ?? "\n- Writing tone: " . $cfg['tone'] . '.';
    $vars['language'] = $vars['language'] ?? "\n- Write in " . $cfg['language'] . '.';
    $vars['seo']      = $vars['seo']      ?? ($cfg['seo_mode']
        ? "\n- Optimise naturally for search engines without keyword stuffing." : '');

    $user = $tpl['user'];
    foreach ($vars as $k => $v) {
        if (is_array($v)) { $v = implode(', ', $v); }
        $v = (string)$v;
        if (trim($v) === '') { $v = 'not provided'; }
        $user = str_replace('{{' . $k . '}}', $v, $user);
    }
    // Any placeholder we did not supply becomes "not provided" rather than leaking braces.
    $user = preg_replace('/\{\{[a-z_]+\}\}/', 'not provided', $user) ?? $user;

    return ['system' => (string)$tpl['system'], 'user' => $user];
}
