<?php

/**
 * AutoTags 通过DeepSeek API自动生成文章标签插件
 * @package AutoTags
 * @author 风之翼灵'BLog
 * @version 1.4.6
 * @link http://www.fungj.com
 */
class AutoTags_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('AutoTags_Plugin', 'generateTags');
        
        return _t('插件已激活');
    }

    /**
     * 禁用插件方法,如果禁用失败,抛出异常
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件已禁用');
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $deepseekApiKey = new Typecho_Widget_Helper_Form_Element_Text('deepseekApiKey', NULL, '', _t('DeepSeek API Key'));
        $form->addInput($deepseekApiKey);

        $contentLength = new Typecho_Widget_Helper_Form_Element_Text('contentLength', NULL, '333', _t('文章正文提取内容长度（字符数），内容长度越大，消耗token数量会越多，请自行选择，默认333字符'));
        $form->addInput($contentLength);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 生成标签
     * @access public
     * @param array $contents 文章内容
     * @param Widget_Contents_Post_Edit $widget 文章编辑 Widget
     * @return void
     */
    public static function generateTags($contents, $widget)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $deepseekApiKey = $options->plugin('AutoTags')->deepseekApiKey;

        if (empty($deepseekApiKey)) {
            return;
        }

        $cid = !empty($contents['cid']) ? $contents['cid'] : $widget->cid;
        if (empty($cid)) {
            return;
        }

        $existingTags = Typecho_Db::get()->fetchObject(
            Typecho_Db::get()->select('COUNT(*) AS count')
                ->from('table.relationships')
                ->join('table.metas', 'table.relationships.mid = table.metas.mid')
                ->where('table.relationships.cid = ?', $cid)
                ->where('table.metas.type = ?', 'tag')
        );
        $tagCount = !empty($existingTags) ? intval($existingTags->count) : 0;

        if ($tagCount > 0) {
            return;
        }

        $title = $contents['title'];
        $contentLength = $options->plugin('AutoTags')->contentLength;
        $text = mb_substr(strip_tags($contents['text']), 0, $contentLength ?: 333, 'utf-8');

        $prompt = "请根据以下文章标题和正文内容，提取关键词作为文章标签。要求：1.标签必须是2-6个字的有效词语；2.标签含义不能重复；3.标签必须是能概括文章标题及正文的关键词；4.随机获取5至10个标签，用逗号','分隔。不要输出任何解释，只输出标签。\n\n标题：{$title}\n\n正文：{$text}";

        $deepseekResponse = self::callDeepSeekApi($deepseekApiKey, $prompt);
        if (empty($deepseekResponse)) {
            return;
        }

        $tags = explode(',', $deepseekResponse);
        $tags = array_map('trim', $tags);
        $tags = array_filter($tags);

        if (empty($tags)) {
            return;
        }

        if ($tagCount > 0) {
            self::clearTags($cid);
        }

        foreach ($tags as $tag) {
            self::addTag($cid, $tag);
        }
    }

    /**
     * 调用 DeepSeek API
     * @access private
     * @param string $apiKey API Key
     * @param string $prompt Prompt
     * @return string
     */
    private static function callDeepSeekApi($apiKey, $prompt)
    {
        $url = 'https://api.deepseek.com/chat/completions';
        $headers = array(
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        );
        $data = array(
            'model' => 'deepseek-v4-flash',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt,
                ),
            ),
            'stream' => false,
            'thinking' => array('type' => 'disabled'),
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return '';
        }

        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        return '';
    }

    /**
     * 清空文章的所有标签
     * @access private
     * @param int $cid 文章 ID
     * @return void
     */
    private static function clearTags($cid)
    {
        if (empty($cid)) {
            return;
        }

        $tags = Typecho_Db::get()->fetchAll(Typecho_Db::get()->select('mid')->from('table.relationships')->where('cid = ?', $cid));

        Typecho_Db::get()->query(Typecho_Db::get()->delete('table.relationships')->where('cid = ?', $cid));

        foreach ($tags as $tag) {
            $mid = $tag['mid'];
            Typecho_Db::get()->query("UPDATE " . Typecho_Db::get()->getPrefix() . "metas SET count = count - 1 WHERE mid = " . $mid . " AND count > 0");
        }
    }

    /**
     * 添加标签
     * @access private
     * @param int $cid 文章 ID
     * @param string $tag 标签名称
     * @return void
     */
    private static function addTag($cid, $tag)
    {
        if (empty($cid)) {
            return;
        }

        $mid = Typecho_Db::get()->fetchObject(Typecho_Db::get()->select('mid')->from('table.metas')->where('name = ?', $tag));
        if (empty($mid)) {
            $insertId = Typecho_Db::get()->query(Typecho_Db::get()->insert('table.metas')->rows(array(
                'name' => $tag,
                'slug' => Typecho_Common::slugName($tag),
                'type' => 'tag',
                'count' => 1
            )));

            if ($insertId) {
                $mid = $insertId;
            } else {
                return;
            }
        } else {
            $mid = $mid->mid;
            Typecho_Db::get()->query("UPDATE " . Typecho_Db::get()->getPrefix() . "metas SET count = count + 1 WHERE mid = " . $mid . " AND count >= 0");
        }

        Typecho_Db::get()->query(Typecho_Db::get()->delete('table.relationships')
            ->where('cid = ?', $cid)
            ->where('mid = ?', $mid));

        Typecho_Db::get()->query(Typecho_Db::get()->insert('table.relationships')->rows(array(
            'cid' => $cid,
            'mid' => $mid,
        )));
    }
}
