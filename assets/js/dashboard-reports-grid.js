(function () {
  // Only run on the dashboard page

  const { themeQuartz, iconSetMaterial } = agGrid;

  if (typeof activ8Reports === 'undefined') {
    console.warn('activ8Reports is not defined.');
    return;
  }

  if (activ8Reports.error) {
    console.error('Postgres error:', activ8Reports.error);
  }

  const el = document.getElementById('reports-analytics');
  if (!el) {
    console.warn('#reports-analytics not found.');
    return;
  }

  agGrid.LicenseManager.setLicenseKey(sdpAgChartKey);

  const activ8MobileTheme = themeQuartz
    .withPart(iconSetMaterial)
    .withParams({
      accentColor: "#57B8DE",
      borderColor: "#0A151F70",
      browserColorScheme: "inherit",
      cellHorizontalPaddingScale: 0,
      fontFamily: "inherit",
      foregroundColor: "#0A151F",
      headerFontSize: 12,
      headerRowBorder: false,
      oddRowBackgroundColor: "#57B8DE26",
      rowBorder: false,
      rowVerticalPaddingScale: 0.5,
      wrapperBorder: false
    });

  const activ8DesktopTheme = themeQuartz
    .withPart(iconSetMaterial)
    .withParams({
      accentColor: "#57B8DE",
      borderColor: "#0A151F70",
      browserColorScheme: "inherit",
      fontFamily: "inherit",
      foregroundColor: "#0A151F",
      headerFontSize: 14,
      headerRowBorder: false,
      oddRowBackgroundColor: "#57B8DE26",
      rowBorder: false,
      wrapperBorder: false
    });

  el.classList.add('ag-theme-quartz');
  if (!el.style.minHeight) el.style.minHeight = '600px';
  el.style.width = '100%';

  const columnDefs = [
    { field: 'as_of_date', headerName: 'As of', sortable: true, filter: 'agDateColumnFilter', width: 120 },
    { field: 'researcher_name', headerName: 'Researcher', sortable: true, filter: true, flex: 1, minWidth: 180 },
    { field: 'ticker', headerName: 'Ticker', sortable: true, filter: true, width: 110 },

    // Flags stored as integers (0/1) -> show Yes/No
    boolCol('is_down', 'Down?'),
    boolCol('down_5pct', '≤ -5%'),
    boolCol('down_10pct', '≤ -10%'),
    boolCol('down_25pct', '≤ -25%'),
    boolCol('down_100pct', '≤ -100%'),

    // Returns / prices
    {
      field: 'avg_return', headerName: 'Avg Return',
      type: 'rightAligned', sortable: true, filter: 'agNumberColumnFilter', width: 130,
      valueFormatter: p => fmtPctFromFrac(p.value) // assumes 0.0512 => 5.12%
    },
    { field: 'start_date', headerName: 'Start Date', sortable: true, filter: 'agDateColumnFilter', width: 120 },
    { field: 'end_date', headerName: 'End Date', sortable: true, filter: 'agDateColumnFilter', width: 120 },
    { field: 'start_price', headerName: 'Start Px', type: 'rightAligned', sortable: true, filter: 'agNumberColumnFilter', width: 110, valueFormatter: fmt2 },
    { field: 'end_price', headerName: 'End Px', type: 'rightAligned', sortable: true, filter: 'agNumberColumnFilter', width: 110, valueFormatter: fmt2 },

    // Computed in SQL above
    { field: 'change_abs', headerName: 'Δ ($)', type: 'rightAligned', sortable: true, filter: 'agNumberColumnFilter', width: 110, valueFormatter: fmtSigned2 },
    { field: 'change_pct', headerName: 'Δ (%)', type: 'rightAligned', sortable: true, filter: 'agNumberColumnFilter', width: 110, valueFormatter: fmtPct },

    // Keep but hide if not needed
    { field: 'created_at', headerName: 'Created At', sortable: true, filter: true, hide: true },
  ];

  // Helpers
  function boolCol(field, headerName) {
    return {
      field, headerName, width: 100, sortable: true, filter: 'agSetColumnFilter',
      valueGetter: p => p.data?.[field] ? 1 : 0,
      valueFormatter: p => (p.value ? 'Yes' : 'No'),
      cellClass: p => (p.value ? 'a8-yes' : 'a8-no')
    };
  }
  function fmt2(p) { const v = p.value; if (v == null || isNaN(v)) return ''; return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function fmtSigned2(p) { const v = p.value; if (v == null || isNaN(v)) return ''; const n = Number(v); const s = n >= 0 ? '+' : ''; return s + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function fmtPct(p) { const v = p.value; if (v == null || isNaN(v)) return ''; return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }
  function fmtPctFromFrac(v) { if (v == null || isNaN(v)) return ''; return (Number(v) * 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%'; }

  const gridOptions = {
    columnDefs,
    rowData: activ8Reports.rows || [],
    defaultColDef: {
      resizable: true,
      sortable: true,
      filter: true,
      minWidth: 100,
      flex: 1
    },
    rowHeight: 48,
    animateRows: true,
    pagination: true,
    paginationPageSize: 25,
    suppressCellFocus: true,
    theme: activ8DesktopTheme,
    onGridReady: params => {
      params.api.sizeColumnsToFit();
      window.addEventListener('resize', () => {
        params.api.sizeColumnsToFit();
      });
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      new agGrid.createGrid(el, gridOptions);
    });
  } else {
    new agGrid.createGrid(el, gridOptions);
  }
})();
