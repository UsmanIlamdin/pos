/**
 * Lazy-load + AJAX filters for Customer Account View sections.
 * Depends on jQuery. No business-logic changes — display/fetch only.
 */
(function ($, window) {
    'use strict';

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var ctx = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, wait);
        };
    }

    function AccountSection(config) {
        this.key = config.key;
        this.$root = $(config.root);
        this.endpoint = config.endpoint;
        this.perPage = config.perPage || 25;
        this.pageParam = config.pageParam;
        this.emptyDefault = config.emptyDefault || '';
        this.emptyFiltered = config.emptyFiltered || '';
        this.renderRows = config.renderRows;
        this.labels = config.labels || {};

        this.page = 1;
        this.totalPages = 1;
        this.total = 0;
        this.loading = false;
        this.loaded = false;
        this.requestSeq = 0;
        this.xhr = null;

        this.$form = this.$root.find('.account-filter-form');
        this.$meta = this.$root.find('.section-meta');
        this.$loading = this.$root.find('.section-loading');
        this.$empty = this.$root.find('.section-empty');
        this.$error = this.$root.find('.section-error');
        this.$tableWrap = this.$root.find('.section-table-wrap');
        this.$body = this.$root.find('.section-body');
        this.$pager = this.$root.find('.section-pager');
        this.$loadMore = this.$root.find('.section-load-more');
    }

    AccountSection.prototype.collectFilters = function () {
        var params = {};
        var $form = this.$form;
        $form.find('input[name], select[name]').each(function () {
            var name = this.name;
            var value = $.trim($(this).val() || '');
            if (name && value !== '') {
                params[name] = value;
            }
        });
        return params;
    };

    AccountSection.prototype.isFiltered = function () {
        var params = this.collectFilters();
        var self = this;
        return Object.keys(params).some(function (key) {
            return key !== self.pageParam;
        });
    };

    AccountSection.prototype.load = function (page, append) {
        var self = this;
        page = Math.max(1, page || 1);
        append = !!append;

        if (self.loading) {
            return;
        }

        var params = self.collectFilters();
        params.per_page = self.perPage;
        params[self.pageParam] = page;
        params.page = page;

        self.replaceMode = !append;
        self.loading = true;
        self.requestSeq += 1;
        var seq = self.requestSeq;

        self.$error.hide().text('');
        self.$loading.show();
        if (!append) {
            self.$empty.hide();
            if (!self.loaded) {
                self.$tableWrap.hide();
            }
            self.$loadMore.prop('disabled', true);
        } else {
            self.$loadMore.prop('disabled', true).text(self.labels.loading || 'Loading…');
        }

        if (self.xhr && self.xhr.readyState !== 4) {
            self.xhr.abort();
        }

        self.xhr = $.ajax({
            url: self.endpoint,
            method: 'GET',
            dataType: 'json',
            data: params,
            cache: false
        }).done(function (response) {
            if (seq !== self.requestSeq) {
                return;
            }
            self.page = response.page || page;
            self.totalPages = response.total_pages || 1;
            self.total = response.total || 0;
            self.loaded = true;

            var rows = response.rows || [];
            if (!append) {
                self.$body.empty();
            }

            if (rows.length === 0 && !append) {
                self.$tableWrap.hide();
                self.$pager.empty();
                self.$loadMore.hide();
                self.$empty.text(
                    (response.filtered || self.isFiltered()) ? self.emptyFiltered : self.emptyDefault
                ).show();
            } else {
                self.$empty.hide();
                self.$tableWrap.show();
                self.renderRows(self.$body, rows, append);
            }

            self.updateMeta();
            self.updatePager();
            self.updateLoadMore();
        }).fail(function (xhr, status) {
            if (status === 'abort' || seq !== self.requestSeq) {
                return;
            }
            self.$error.text(self.labels.error || 'Unable to load records.').show();
            if (!self.loaded) {
                self.$tableWrap.hide();
                self.$empty.hide();
            }
        }).always(function () {
            if (seq !== self.requestSeq) {
                return;
            }
            self.loading = false;
            self.$loading.hide();
            self.$loadMore.prop('disabled', false).text(self.labels.loadMore || 'Load More');
        });
    };

    AccountSection.prototype.updateMeta = function () {
        if (!this.$meta.length) {
            return;
        }
        var start = 0;
        if (this.total) {
            start = this.replaceMode ? ((this.page - 1) * this.perPage + 1) : 1;
        }
        var end = Math.min(this.page * this.perPage, this.total);
        this.$meta.text(
            (this.labels.showing || 'Showing') + ' ' + start + '–' + end +
            ' ' + (this.labels.of || 'of') + ' ' + this.total
        );
    };

    AccountSection.prototype.updatePager = function () {
        var self = this;
        self.$pager.empty();
        if (self.totalPages <= 1) {
            return;
        }
        for (var i = 1; i <= self.totalPages; i++) {
            var active = i === self.page ? ' btn-primary' : ' btn-default';
            self.$pager.append(
                $('<button type="button" class="btn btn-xs section-page-btn' + active + '"></button>')
                    .text(i)
                    .attr('data-page', i)
            );
        }
    };

    AccountSection.prototype.updateLoadMore = function () {
        if (this.page < this.totalPages && this.total > 0) {
            this.$loadMore.show();
        } else {
            this.$loadMore.hide();
        }
    };

    AccountSection.prototype.resetFilters = function () {
        this.$form.find('.filter-search').val('');
        this.$form.find('.filter-select').val('');
        this.$form.find('.filter-from, .filter-to').val('');
        this.load(1, false);
    };

    AccountSection.prototype.bind = function () {
        var self = this;

        self.$form.on('click', '.filter-apply', function () {
            self.load(1, false);
        });
        self.$form.on('click', '.filter-reset', function () {
            self.resetFilters();
        });
        self.$form.on('change', '.filter-select, .filter-from, .filter-to', function () {
            self.load(1, false);
        });
        self.$form.on('input', '.filter-search', debounce(function () {
            self.load(1, false);
        }, 400));
        self.$form.on('keydown', '.filter-search', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                self.load(1, false);
            }
        });

        self.$pager.on('click', '.section-page-btn', function () {
            var page = parseInt($(this).attr('data-page'), 10) || 1;
            self.load(page, false);
            $('html, body').animate({ scrollTop: self.$root.offset().top - 20 }, 200);
        });

        self.$loadMore.on('click', function () {
            if (self.page < self.totalPages) {
                self.load(self.page + 1, true);
            }
        });
    };

    AccountSection.prototype.observe = function () {
        var self = this;
        self.bind();

        if (!('IntersectionObserver' in window)) {
            self.load(1, false);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting && !self.loaded && !self.loading) {
                    self.load(1, false);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '120px 0px', threshold: 0.01 });

        observer.observe(self.$root.get(0));
    };

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function initAccountView(options) {
        var labels = options.labels || {};
        var perPage = options.perPage || 25;
        var customerId = options.customerId;
        var urls = options.urls || {};

        var sections = [
            new AccountSection({
                key: 'outstanding',
                root: '#section-outstanding',
                endpoint: urls.outstanding,
                perPage: perPage,
                pageParam: 'outstanding_page',
                emptyDefault: labels.noOutstanding,
                emptyFiltered: labels.noOutstandingFiltered,
                labels: labels,
                renderRows: function ($body, rows) {
                    rows.forEach(function (row) {
                        $body.append(
                            '<tr>' +
                            '<td>' + escapeHtml(row.sale_id) + '</td>' +
                            '<td>' + row.date + '</td>' +
                            '<td>' + row.invoice_number + '</td>' +
                            '<td class="text-right">' + row.total + '</td>' +
                            '<td class="text-right">' + row.paid + '</td>' +
                            '<td class="text-right">' + row.outstanding + '</td>' +
                            '</tr>'
                        );
                    });
                }
            }),
            new AccountSection({
                key: 'ci',
                root: '#section-ci',
                endpoint: urls.invoices,
                perPage: perPage,
                pageParam: 'ci_page',
                emptyDefault: labels.noCi,
                emptyFiltered: labels.noCiFiltered,
                labels: labels,
                renderRows: function ($body, rows) {
                    rows.forEach(function (row) {
                        $body.append(
                            '<tr>' +
                            '<td>' + row.invoice_number + '</td>' +
                            '<td>' + row.invoice_date + '</td>' +
                            '<td>' + row.status + '</td>' +
                            '<td class="text-right">' + row.total + '</td>' +
                            '<td class="text-right">' + row.paid + '</td>' +
                            '<td class="text-right">' + row.balance + '</td>' +
                            '<td>' + row.actions + '</td>' +
                            '</tr>'
                        );
                    });
                }
            }),
            new AccountSection({
                key: 'ledger',
                root: '#section-ledger',
                endpoint: urls.ledger,
                perPage: perPage,
                pageParam: 'ledger_page',
                emptyDefault: labels.noLedger,
                emptyFiltered: labels.noLedgerFiltered,
                labels: labels,
                renderRows: function ($body, rows) {
                    rows.forEach(function (row) {
                        $body.append(
                            '<tr class="ledger-parent" data-sale-id="' + escapeHtml(row.sale_id) + '">' +
                            '<td><button type="button" class="btn btn-xs btn-default ledger-toggle">+</button></td>' +
                            '<td>' + row.date + '</td>' +
                            '<td>' + row.reference + '</td>' +
                            '<td class="text-right">' + row.sale_total + '</td>' +
                            '<td class="text-right">' + row.payments + '</td>' +
                            '<td class="text-right">' + row.credits + '</td>' +
                            '<td class="text-right">' + row.balance + '</td>' +
                            '<td>' + row.status + '</td>' +
                            '</tr>'
                        );
                        (row.children || []).forEach(function (child) {
                            var actions = '';
                            if (child.can_void) {
                                actions += '<button type="button" class="btn btn-xs btn-danger void-payment" data-payment-id="' +
                                    escapeHtml(child.payment_id) + '">' + escapeHtml(labels.voidPayment || 'Void') + '</button> ';
                            }
                            if (child.can_reallocate) {
                                actions += '<button type="button" class="btn btn-xs btn-warning reallocate-payment" data-payment-id="' +
                                    escapeHtml(child.payment_id) + '">' + escapeHtml(labels.changeAllocation || 'Change') + '</button>';
                            }
                            $body.append(
                                '<tr class="ledger-child" data-parent="' + escapeHtml(row.sale_id) + '" style="display:none; background:#f9f9f9;">' +
                                '<td></td>' +
                                '<td>' + child.date + '</td>' +
                                '<td colspan="2">' + child.reference +
                                ' <small>(' + escapeHtml(child.source) + ')</small></td>' +
                                '<td class="text-right" colspan="2">' + child.amount + '</td>' +
                                '<td colspan="2">' + actions + '</td>' +
                                '</tr>'
                            );
                        });
                    });
                }
            }),
            new AccountSection({
                key: 'transaction',
                root: '#section-tx',
                endpoint: urls.transactions,
                perPage: perPage,
                pageParam: 'transaction_page',
                emptyDefault: labels.noTx,
                emptyFiltered: labels.noTxFiltered,
                labels: labels,
                renderRows: function ($body, rows) {
                    rows.forEach(function (row) {
                        var ref = row.reference;
                        if (row.allocated_to) {
                            ref += '<br><small>' + row.allocated_to + '</small>';
                        }
                        if (row.memo) {
                            ref += '<br><small>' + row.memo + '</small>';
                        }
                        $body.append(
                            '<tr>' +
                            '<td>' + row.date + '</td>' +
                            '<td>' + ref + '</td>' +
                            '<td class="text-right">' + (row.debit || '') + '</td>' +
                            '<td class="text-right">' + (row.credit || '') + '</td>' +
                            '<td class="text-right">' + row.balance + '</td>' +
                            '</tr>'
                        );
                    });
                }
            }),
            new AccountSection({
                key: 'activity',
                root: '#section-activity',
                endpoint: urls.activity,
                perPage: perPage,
                pageParam: 'activity_page',
                emptyDefault: labels.noActivity,
                emptyFiltered: labels.noActivityFiltered,
                labels: labels,
                renderRows: function ($body, rows) {
                    rows.forEach(function (row) {
                        $body.append(
                            '<tr>' +
                            '<td>' + row.date + '</td>' +
                            '<td>' + row.reference + '</td>' +
                            '<td>' + (row.memo || '') + '</td>' +
                            '</tr>'
                        );
                    });
                }
            })
        ];

        sections.forEach(function (section) {
            if (section.$root.length) {
                section.observe();
            }
        });

        return sections;
    }

    window.AccountsViewLazy = {
        init: initAccountView
    };
})(jQuery, window);
