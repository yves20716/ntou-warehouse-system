(() => {
    const pageSize = 10;
    const isPreview = document.querySelector('#main')?.dataset.view === 'overview';

    document.querySelectorAll('.table-scroll[aria-label]').forEach((region, index) => {
        if (region.dataset.tableTools === 'ready') return;
        const table = region.querySelector('table');
        if (!table) return;
        const rows = [...table.tBodies].flatMap((body) => [...body.rows]);
        if (!rows.length) return;
        region.dataset.tableTools = 'ready';
        if (!region.id) region.id = `table-records-${index + 1}`;

        const label = region.getAttribute('aria-label');
        const choices = isPreview ? [] : label === '庫存批次' ? ['全部', '在庫', '已出清']
            : label === '進出貨明細' ? ['全部', '入庫', '出庫']
            : label === '收發申請' ? ['全部', '待處理', '已完成', '未受理', '已取消'] : [];
        const limit = isPreview ? rows.length : pageSize;
        const records = rows.map((row) => ({ row, status: row.querySelector('.pill')?.textContent.trim() ?? '' }));
        let selected = '全部';
        let page = 1;

        const tools = document.createElement('div');
        tools.className = 'table-tools';
        const count = document.createElement('p');
        count.className = 'table-count';
        count.setAttribute('role', 'status');
        count.setAttribute('aria-live', 'polite');
        count.setAttribute('aria-atomic', 'true');

        const filters = [];
        if (choices.length) {
            const tabs = document.createElement('div');
            tabs.className = 'table-tabs';
            tabs.setAttribute('role', 'group');
            tabs.setAttribute('aria-label', `${label}狀態`);
            for (const choice of choices) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'table-tab';
                button.textContent = choice;
                button.setAttribute('aria-controls', region.id);
                button.addEventListener('click', () => {
                    selected = choice;
                    page = 1;
                    render();
                });
                filters.push([button, choice]);
                tabs.append(button);
            }
            tools.append(tabs);
        }
        tools.append(count);

        const pagination = document.createElement('nav');
        pagination.className = 'table-pagination';
        pagination.setAttribute('aria-label', `${label}分頁`);
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'table-pagebutton';
        previous.textContent = '上一頁';
        previous.setAttribute('aria-controls', region.id);
        const position = document.createElement('span');
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'table-pagebutton';
        next.textContent = '下一頁';
        next.setAttribute('aria-controls', region.id);
        previous.addEventListener('click', () => { page--; render(); });
        next.addEventListener('click', () => { page++; render(); });
        pagination.append(previous, position, next);
        if (!isPreview && rows.length > pageSize) tools.append(pagination);

        const empty = document.createElement('div');
        empty.className = 'filter-empty';
        empty.hidden = true;
        const emptyMessage = document.createElement('p');
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'table-pagebutton';
        clear.textContent = '清除狀態篩選';
        clear.setAttribute('aria-controls', region.id);
        clear.addEventListener('click', () => {
            selected = '全部';
            page = 1;
            render();
            filters[0]?.[0].focus();
        });
        empty.append(emptyMessage, clear);
        region.before(tools, empty);

        function render() {
            const matching = records.filter((record) => selected === '全部' || record.status === selected);
            const pages = Math.max(1, Math.ceil(matching.length / limit));
            page = Math.max(1, Math.min(page, pages));
            const start = (page - 1) * limit;
            const visible = new Set(matching.slice(start, start + limit).map((record) => record.row));
            for (const record of records) {
                record.row.hidden = !visible.has(record.row);
                if (record.row.hidden) {
                    record.row.querySelectorAll('details[open]').forEach((details) => { details.open = false; });
                }
            }
            for (const [button, choice] of filters) button.setAttribute('aria-pressed', String(choice === selected));
            const end = Math.min(start + limit, matching.length);
            count.textContent = matching.length
                ? (isPreview ? `顯示 ${matching.length} 筆資料` : pages > 1 ? `顯示 ${start + 1}–${end} 筆，共 ${matching.length} 筆` : `共 ${matching.length} 筆`)
                : '共 0 筆';
            if (selected !== '全部') count.textContent += ` · ${selected}`;
            pagination.hidden = pages <= 1;
            previous.disabled = page <= 1;
            next.disabled = page >= pages;
            position.textContent = `${page} / ${pages}`;
            position.setAttribute('aria-label', `第 ${page} 頁，共 ${pages} 頁`);
            empty.hidden = matching.length > 0;
            region.hidden = matching.length === 0;
            emptyMessage.textContent = label === '庫存批次'
                ? `目前沒有${selected}的批次。` : label === '收發申請' ? `目前沒有${selected}的申請。` : `目前沒有${selected}紀錄。`;
        }

        render();
    });
})();
