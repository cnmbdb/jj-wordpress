/*
 * @Author: Qinver
 * @Url: zibll.com
 * @Date: 2021-08-11 16:08:49
 * @LastEditTime: 2025-09-28 22:18:09
 */

(function () {
    var _body = $('body');

    //发布文章提交
    _body.on('click', '.bbs-posts-submit', function () {
        var _this = $(this),
            type = _this.attr('action'),
            form = _this.parents('form'),
            data = form.serializeObject();
        data.action = type;
        if (tinyMCE) {
            data.post_content = tinyMCE.activeEditor.getContent();
        }

        //封面数据
        var featured = $('.featured-edit');
        if (featured.length) {
            var featured_data = featured.data('featured_data');
            data.featured_data = featured_data ? featured_data.data : false;
        }

        zib_ajax(_this, data, function (n) {
            if (n.html) {
                form.find('.submit-text').html(n.html);
            }
            n.post_id && form.find('input[name="post_id"]').val(n.post_id);
        });
    });

    _body.on('change', "[name='vote[type]']", function () {
        vote_change($(this));
    });

    function vote_change(_this) {
        var _vote = $('.vote-options');
        if (_this.length && _vote.length) {
            if (_this.val() == 'pk') {
                _vote.children().eq(1).nextAll().remove();
                _vote.data('max', 2).cloneable();
            } else {
                _vote.data('max', 10).cloneable();
            }
        }
    }

    $(document).ready(function () {
        setTimeout(function () {
            vote_change($("[name='vote[type]']"));
        }, 100);
    });

    //投票组件
    $.fn.vote = function () {
        var ajax_url = _win.ajax_url;
        var text = 'vote';
        var voted_text = text + 'd';
        var start_text = text + '-start';
        var click = 'click';
        var allow = text + '-allow';
        var loading = text + '-loading';
        var user_cuont = text + '-user-count';
        var ok = text + '-ok';
        var progress = text + '-progress'; //进度条
        var percentage = text + '-percentage'; //百分比
        var number = text + '-number'; //百分比
        var ajax_action = 'submit_' + text;
        var item_e = '.' + text + '-item';
        var submit_e = '.' + text + '-submit';
        var is_choice = 'is-' + voted_text;
        var is_on = 'is-on';

        function show(_this, add = 0) {
            var voted_all = _this.data(voted_text + '-all') + add;
            var type = _this.data('type');

            _this.find(item_e).each(function () {
                var _item = $(this);
                var voted = _item.data(voted_text);
                if (!_item.children('.' + progress).length) {
                    _item.prepend('<div class="' + progress + '"></div>');
                }
                var percentage_data = ((voted / voted_all) * 100).toFixed(4);
                if (type == 'px' && !voted_all) percentage_data = '50%';
                setTimeout(function () {
                    _item.children('.' + progress).css('width', percentage_data + '%');
                }, 200);
                _item.children('.' + percentage).html(~~percentage_data + '%');
                _item.children('.' + number).html(voted + '票');
            });
        }

        function ajax(_main_this, data) {
            data.action = ajax_action;
            if (!_main_this.hasClass(loading)) {
                //防止多次点击
                _main_this.addClass(loading);
                $.post(
                    ajax_url,
                    data,
                    function (result) {
                        if (result.data) {
                            _main_this
                                .off(click)
                                .removeClass(loading + ' ' + allow)
                                .addClass(ok);
                            show(_main_this, data.voted.length);
                            var _user_cuont = _main_this.find('.' + user_cuont);
                            if (_user_cuont.length) {
                                _user_cuont.text(~~_user_cuont.text() + 1);
                            }
                            _main_this.find('.' + start_text).html('投票成功');
                            _main_this.find(submit_e).hide();
                        } else {
                            _main_this.removeClass(loading);
                        }
                    },
                    'json'
                );
            }
        }

        return this.each(function () {
            var _this = $(this);
            var type = _this.data('type');
            var pist_id = _this.data('post-id');
            var data = {
                id: pist_id,
            };
            show(_this);
            if (!_this.hasClass(allow) || _this.data(is_on)) return;

            if (type === 'multiple') {
                var _submit = _this.find(submit_e);
                _this
                    .data(is_on, true)
                    .on(click, item_e, function () {
                        var _item = $(this);
                        var voted = _item.data(voted_text);
                        if (_item.hasClass(is_choice)) {
                            _item.removeClass(is_choice).data(voted_text, voted - 1);
                        } else {
                            _item.addClass(is_choice).data(voted_text, voted + 1);
                        }

                        if (_this.find(item_e + '.' + is_choice).length) {
                            _submit.show();
                        } else {
                            _submit.hide();
                        }
                    })
                    .on(click, submit_e, function () {
                        if (!_this.hasClass(loading)) {
                            //防止多次点击
                            var voted = [];
                            _this.find(item_e).each(function (index) {
                                if ($(this).hasClass(is_choice)) {
                                    voted.push(index);
                                }
                            });
                            data.voted = voted;
                            ajax(_this, data);
                        }
                    });
            } else {
                _this.data(is_on, true).on(click, item_e, function () {
                    if (!_this.hasClass(loading)) {
                        //防止多次点击
                        var _item = $(this).addClass(is_choice);
                        var voted = _item.data(voted_text);
                        _item.data(voted_text, voted + 1);
                        var index = _item.data('index');
                        data.voted = [index];
                        ajax(_this, data);
                    }
                });
            }
        });
    };

    //图片延迟懒加载-ias自动加载
    document.addEventListener('lazybeforeunveil', function (e) {
        var _this = $(e.target);
        if (_this.hasClass('vote-box')) {
            setTimeout(function () {
                _this.vote();
            }, 500);
        }
    });

    //挂钩添加term后的处理动作
    _body.on('miniuploaded', '[term-taxonomy]', function (a, data) {
        if (!data.term_id) return;
        if (data.type === 'add') {
            var container, label;
            switch (data.taxonomy) {
                case 'plate_cat':
                    container = $('.plate-cat-radio');
                    if (container.length) {
                        container.find('.container-null').remove();
                        label = $('<label><input type="radio" name="cat" value="' + data.term_id + '"><span class="p2-10 mr6 but but-radio">' + data.term.name + '</span></label>');
                    }
                    break;
                case 'forum_tag':
                    container = $('#tag_select_tab_main');
                    if (container.length) {
                        label = $('<span data-multiple="5" data-for="tag" data-value="' + data.term_id + '" class="tag-list ajax-item pointer"><span class="badg mm3">' + data.term.name + '</span></span>');
                        $('[href="#tag_select_tab_main"]').click();
                    }
                    break;
                case 'forum_topic':
                    container = $('#topic_select_tab_main');
                    if (container.length) {
                        label = $('<div data-for="topic" data-value="' + data.term_id + '" class="flex padding-10 topic-list ajax-item pointer"><div class="square-box mr10 thumb">' + (data.image_url ? '<img src="' + data.image_url + '" class="fit-cover radius4">' : '') + '</div><div class="info"><div class="name"><svg class="icon" aria-hidden="true"><use xlink:href="#icon-topic"></use></svg>' + data.term.name + '<svg class="icon" aria-hidden="true"><use xlink:href="#icon-topic"></use></svg></div><div class="muted-3-color em09 desc"><span class="mr20">帖子:0</span><span class="">2秒前创建</span></div></div></div>');
                        $('[href="#topic_select_tab_main"]').click();
                    }
                    break;
            }
            container.prepend(label);
            return label.click();
        }

        window.location.href = data.term_url;
        window.location.reload;
    });

    _body.on('miniuploaded', '[plate-save]', function (a, data) {
        if (!data.id) return;

        if (data.type === 'add') {
            var container = $('#plate_select_tab_main');
            if (container.length) {
                var label = $('<div data-for="plate" data-value="' + data.id + '" class="flex padding-10 plate-list ajax-item pointer"><div class="square-box mr10 thumb">' + (data.image_url ? '<img src="' + data.image_url + '" class="radius-cover">' : '') + '</div><div class="info"><div class="name">' + data.post.post_title + '</div><div class="muted-3-color em09 desc mt3">3秒前创建</div></div></div>');
                container.prepend(label);
                $('[href="#plate_select_tab_main"]').click();
                return label.click();
            }
        }
        window.location.href = data.url;
        window.location.reload;
    });

    //回答采纳后，将按钮标记为已采纳
    _body.on('zib_ajax.success', '.answer-adopt-submit', function (e, n) {
        if (n && n.comment_id && n.badeg) {
            $('.answer-adopt-id-' + n.comment_id).prop('outerHTML', n.badeg);
        }
    });
})();
