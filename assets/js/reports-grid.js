// /assets/js/reports-grid.js
const { themeQuartz, iconSetMaterial } = agGrid;


function displayReportsGrid(){
  if (typeof sdpReportsData === 'undefined' || !sdpReportsData.rows) {
    console.log("No sdpReportsData found.");
    return;
  }

  // Inject AG Grid custom styles dynamically
(function injectAGGridCustomStyles() {
  const css = `
    .chg-pos { font-weight: 600; }
    .chg-neg { font-weight: 600; }
    .chg-pos.ag-cell-value { color: green; }
    .chg-neg.ag-cell-value { color: red; }
  `;

  // Only add if not already present
  if (!document.getElementById('ag-grid-custom-styles')) {
    const style = document.createElement('style');
    style.id = 'ag-grid-custom-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }
})();


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

  const desktopColumnDefs = [
    {
      headerName: 'Researcher',
      field: 'researcherName',
      sortable: true,
      filter: true,
      minWidth: 220,
      cellRenderer: params => {
        const { researcherLogo, researcherName, reportUrl } = params.data || {};

        const wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '8px';

        if (researcherLogo) {
          const img = document.createElement('img');
          img.src = researcherLogo;
          img.alt = researcherName || '';
          img.style.objectFit = 'contain';
          img.style.width = '32px';
          img.style.height = '32px';
          img.style.borderRadius = '50%';
          wrap.appendChild(img);
        }

        const nameSpan = document.createElement('span');
        nameSpan.textContent = researcherName || '';
        wrap.appendChild(nameSpan);

        // Hidden link element (keeps your prior behavior if you rely on it)
        const hiddenLink = document.createElement('a');
        hiddenLink.href = reportUrl || '#';
        hiddenLink.className = 'report-link';
        hiddenLink.style.display = 'none';
        hiddenLink.textContent = reportUrl || '';
        wrap.appendChild(hiddenLink);

        return wrap;
      }
    },
    { headerName: 'Company', field: 'companyName', sortable: true, filter: true, minWidth: 180 },
    { headerName: 'Symbol', field: 'symbol', sortable: true, filter: true, width: 120 },
    {
      headerName: 'Date',
      valueGetter: params => params.data?.dateDisplay || '',
      // sort by ISO, show display
      comparator: (a, b, nodeA, nodeB) => {
        const dA = nodeA?.data?.dateISO || '';
        const dB = nodeB?.data?.dateISO || '';
        if (dA === dB) return 0;
        return dA > dB ? 1 : -1;
      },
      sortable: true,
      filter: 'agDateColumnFilter',
      minWidth: 130,
    },
    {
      headerName: 'Price',
      field: 'price',
      valueFormatter: p => (typeof p.value === 'number' ? `${p.value.toFixed(2)}` : ''),
      sortable: true,
      filter: 'agNumberColumnFilter',
      minWidth: 120,
      cellClassRules: {
        'chg-pos': p => typeof p.data?.percent === 'number' && p.data.percent > 0,
        'chg-neg': p => typeof p.data?.percent === 'number' && p.data.percent < 0,
      },
    },
    {
      headerName: '% Since',
      field: 'percent',
      valueFormatter: p => (typeof p.value === 'number' ? `${p.value.toFixed(2)}%` : ''),
      sortable: true,
      filter: 'agNumberColumnFilter',
      minWidth: 120,
      cellClassRules: {
        'chg-pos': p => typeof p.value === 'number' && p.value > 0,
        'chg-neg': p => typeof p.value === 'number' && p.value < 0,
      },
    },
  ];

  const mobileColumnDefs = [
    {
      headerName: 'Researcher',
      field: 'researcherName',
      sortable: true,
      filter: true,
      minWidth: 120,
      cellStyle: {textOverflow: 'fade clip', whiteSpace: 'nowrap', overflow: 'hidden', display: 'block' }
    },
    { headerName: 'Symbol', field: 'symbol', sortable: true, filter: true, minWidth: 50 },
    {
      headerName: 'Date',
      valueGetter: params => params.data?.dateDisplay || '',
      comparator: (a, b, nodeA, nodeB) => {
        const dA = nodeA?.data?.dateISO || '';
        const dB = nodeB?.data?.dateISO || '';
        if (dA === dB) return 0;
        return dA > dB ? 1 : -1;
      },
      sortable: true,
      filter: 'agDateColumnFilter',
      minWidth: 80,
    },
    {
      headerName: 'Price (% Since)',
      field: 'price',
      valueFormatter: p => {
        const price = typeof p.value === 'number' ? p.value.toFixed(2) : '';
        const percent = typeof p.data?.percent === 'number' ? `${p.data.percent.toFixed(2)}%` : '';
        return percent ? `${price} (${percent})` : price;
      },
      sortable: true,
      filter: 'agNumberColumnFilter',
      minWidth: 140,
      cellClassRules: {
        'chg-pos': p => typeof p.data?.percent === 'number' && p.data.percent > 0,
        'chg-neg': p => typeof p.data?.percent === 'number' && p.data.percent < 0,
      },
    },
  ];

  const isMobile = window.innerWidth < 768;
  const theme = isMobile ? activ8MobileTheme : activ8DesktopTheme;
  const columnDefs = isMobile ? mobileColumnDefs : desktopColumnDefs;
  const rowHeight = isMobile ? 20 : 48;

  const gridEl = document.querySelector('#reportsGrid');
  if (!gridEl) return;

// Read config from HTML data-attributes
const wantsPagination = (gridEl.getAttribute('data-pagination') || '').toLowerCase() === 'true';
const pageSizeAttr = parseInt(gridEl.getAttribute('data-page-size') || '', 10);
const pageSize = Number.isFinite(pageSizeAttr) && pageSizeAttr > 0 ? pageSizeAttr : 25;

const gridOptions = {
  theme,
  columnDefs,
  rowData: (sdpReportsData && sdpReportsData.rows) ? sdpReportsData.rows : [],
  defaultColDef: {
    resizable: true,
    sortable: true,
    filter: true,
    flex: 1,
  },
  animateRows: true,

  // If you want the grid to grow/shrink to the page size, keep autoHeight:
  domLayout: 'autoHeight',

  // Client-side pagination
  pagination: wantsPagination,
  paginationPageSize: pageSize,
  paginationPageSizeSelector: false,

  // Optional: match your earlier row height choice
  rowHeight,
  
  onRowClicked: (event) => {
    const url = event.data?.reportUrl;
    if (url) {
      window.location.href = url;
    }
  }
};

if (typeof agGrid.createGrid === 'function') {
  agGrid.createGrid(gridEl, gridOptions);
} else {
  new agGrid.Grid(gridEl, gridOptions);
}

};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', displayReportsGrid)
} else {
  displayReportsGrid();
}