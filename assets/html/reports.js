document.addEventListener('DOMContentLoaded', function () {
  const table = new gridjs.Grid({
    columns: [
      {
        name: 'Researcher',
        formatter: (cell) => gridjs.html(cell)
      },
      'Company',
      'Symbol',
      'Date',
      'Report Price',
      {
        name: 'Change',
        formatter: (cell) => {
          const value = parseFloat(cell);
          const color = value > 0 ? 'green' : value < 0 ? 'red' : 'black';
          return gridjs.html(`<span style="color:${color}">${cell}</span>`);
        }
      }
    ],
    data: sdpReportsData.rows.map(row => row.slice(0, 6)), // still hide the 7th element (URL) from the table
    search: true,
    pagination: {
      enabled: true,
      limit: 50
    },
    sort: true,
    className: {
      td: 'sdp-cell',
      tr: 'sdp-row'
    }
  });

  table.render(document.getElementById("report-grid"));

  // Add clickable rows
  setTimeout(() => {
    const rows = document.querySelectorAll(".sdp-row");
    rows.forEach((row, i) => {
      const url = sdpReportsData.rows[i][6];
      row.style.cursor = "pointer";
      row.addEventListener("click", () => {
        window.location.href = url;
      });
    });
  }, 500);
});
