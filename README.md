# AutoTags自动标签生成插件 v1.4.3

## 功能描述

AutoTags是一个Typecho插件，能够自动根据文章内容生成标签。它通过调用DeepSeek API分析文章内容，提取关键词作为标签，并自动添加到文章中。

## 安装方法

1. 下载插件压缩包并解压
2. 将解压后的文件夹重命名为 `AutoTags`
3. 将文件夹上传到 Typecho 的插件目录 `/usr/plugins/`
4. 在 Typecho 后台激活插件

## 配置说明

1. 激活插件后，进入插件设置页面
2. 输入您的 DeepSeek API Key
3. 设置内容长度（字符数）：内容长度越大，消耗的API token数量会越多，默认333字符
4. 可以通过修改插件$prompt变量来调整标签生成规则及标签获取的数量：
   - "请根据以下文章标题和正文内容，提取关键词作为文章标签，保留原文内容，标签含义不能重复，随机获取1至10个标签，用逗号分隔：\n\n标题：{$title}\n\n正文：{$text}"
   - 编辑Plugin.php文件中的generateTags方法
   - 修改$prompt变量的内容格式
5. 保存设置

## 使用示例

插件激活并配置后，在发布或修改文章时会自动执行以下操作：

1. 提取文章标题和前333个字符的正文内容
2. 发送到 DeepSeek API 进行分析
3. 获取返回的关键词作为标签
4. 自动将标签添加到文章中

## 注意事项

1. 需要有效的 DeepSeek API Key 才能使用
2. API 调用可能会受到网络状况影响
3. DeepSeek API 需要自行注册和配置，并且DeepSeek API需要支付一定的费用，请熟知

## todo

- [ ] 接入其他AI模型

## 更新日志

### v1.4.3 (2026-06-17)

- 替换DeepSeek模型：将deepseek-chat更换为deepseek-v4-flash，原模型deepseek-chat将于2026/07/24弃用。
- 替换DeepSeek api url：将deepseek api url替换为官方最新地址。
