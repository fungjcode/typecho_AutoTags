<?php

/**
 * AutoTags 通过DeepSeek API自动生成文章标签插件
 * @package AutoTags
 * @author 风之翼灵‘BLog
 * @version 1.4.3
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
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishModify = array('AutoTags_Plugin', 'generateTags');
        
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

        $title = $contents['title'];
        $options = Typecho_Widget::widget('Widget_Options');
        $contentLength = $options->plugin('AutoTags')->contentLength;
        $text = mb_substr(strip_tags($contents['text']), 0, $contentLength ?: 333, 'utf-8');

        $prompt = "请根据以下文章标题和正文内容，提取关键词作为文章标签，保留原文内容，标签含义不能重复，随机获取1至10个标签，用逗号分隔：\n\n标题：{$title}\n\n正文：{$text}";


        $deepseekResponse = self::callDeepSeekApi($deepseekApiKey, $prompt);
        if (!empty($deepseekResponse)) {
            $tags = explode(',', $deepseekResponse);
            $tags = array_map('trim', $tags);
            $tags = array_filter($tags);

            // 使用 Typecho 的标签 API 添加标签
            $cid = $widget->cid; // 使用 $widget->cid 获取文章 ID
            if (empty($cid)) {

                return;
            }
            foreach ($tags as $tag) {
                self::addTag($cid, $tag);
            }
        } else {
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
        );



        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {

            return $result['choices'][0]['message']['content'];
        }


        return '';
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
            // 更新count时使用原生SQL表达式确保不会变成负数
            Typecho_Db::get()->query("UPDATE " . Typecho_Db::get()->getPrefix() . "metas SET count = count + 1 WHERE mid = " . $mid . " AND count >= 0");
        }

        // 先删除旧的关系，再添加新的关系
        Typecho_Db::get()->query(Typecho_Db::get()->delete('table.relationships')
            ->where('cid = ?', $cid)
            ->where('mid = ?', $mid));

        Typecho_Db::get()->query(Typecho_Db::get()->insert('table.relationships')->rows(array(
            'cid' => $cid,
            'mid' => $mid,
        )));
    }
}