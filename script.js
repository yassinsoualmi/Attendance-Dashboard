$(document).ready(function() {

  function computeStatus(absences, participation) {
    if (absences >= 5) {
      return {
        message: "Excluded - too many absences. You need to participate more",
        rowClass: "red"
      };
    }

    const attendancePart = absences <= 2 ? "Good attendance" : "Average attendance";
    let participationPart = "needs to participate more";
    if (participation >= 5) {
      participationPart = "excellent participation";
    } else if (participation >= 3) {
      participationPart = "good participation";
    }

    return {
      message: `${attendancePart} - ${participationPart}`,
      rowClass: absences <= 2 ? "green" : "yellow"
    };
  }

  function updateTable() {
    $("#attendance tbody tr").each(function() {
      let abs = 0;
      let par = 0;

      $(this).find("td:nth-child(n+3):nth-child(-n+8)").each(function() {
        const cellText = $(this).text().trim().toUpperCase();
        if (cellText === "P") {
          par++;
        } else {
          abs++;
        }
      });

      const manualAbsRaw = $(this).data("manualAbsOverride");
      const manualAbs = manualAbsRaw !== undefined ? parseInt(manualAbsRaw, 10) : NaN;
      const absToUse = Number.isInteger(manualAbs) && manualAbs >= 0 ? manualAbs : abs;

      const status = computeStatus(absToUse, par);
      $(this).find("td:eq(8)").text(`${absToUse} Abs`);
      $(this).find("td:eq(10)").text(`${par} Par`);
      $(this).find("td:eq(11)").text(status.message);

      $(this).removeClass("green yellow red");
      if (status.rowClass) {
        $(this).addClass(status.rowClass);
      }
    });
  }

  function setAbsenceError(message) {
    $("#editAbsError").text(message || "");
  }

  // Elements and helpers powering the custom absence modal.
  const $absenceModal = $("#absenceModal");
  const $absenceModalForm = $("#absenceModalForm");
  const $absenceModalInput = $("#absenceModalInput");
  const $absenceModalStudent = $("#absenceModalStudent");
  const $absenceModalCancel = $("#absenceModalCancel");
  let $activeAbsRow = null;

  function openAbsenceModal($row) {
    $activeAbsRow = $row;
    const studentName = `${$row.find("td:eq(0)").text()} ${$row.find("td:eq(1)").text()}`.trim() || "this student";
    const currentAbs = parseInt($row.find("td:eq(8)").text(), 10) || 0;
    $absenceModalStudent.text(studentName);
    $absenceModalInput.val(currentAbs);
    setAbsenceError("");
    $absenceModal.attr("aria-hidden", "false").addClass("visible");
    setTimeout(() => {
      $absenceModalInput.trigger("focus").select();
    }, 0);
  }

  function closeAbsenceModal() {
    $absenceModal.removeClass("visible").attr("aria-hidden", "true");
    if ($absenceModalForm.length) {
      $absenceModalForm[0].reset();
    }
    $activeAbsRow = null;
  }

  // Popup for viewing a student's current absence count.
  const $infoPopup = $("#infoPopup");
  const $infoPopupName = $("#infoPopupName");
  const $infoPopupDetails = $("#infoPopupDetails");
  const $infoPopupOk = $("#infoPopupOk");

  function showAbsenceInfo(name, count) {
    const displayName = name.trim() || "Unknown student";
    const absValue = Number.isFinite(count) && count >= 0 ? count : 0;
    $infoPopupName.text(displayName);
    $infoPopupDetails.text(`- ${absValue} Abs`);
    $infoPopup.attr("aria-hidden", "false").addClass("visible");
    setTimeout(() => $infoPopupOk.trigger("focus"), 0);
  }

  function closeInfoPopup() {
    $infoPopup.removeClass("visible").attr("aria-hidden", "true");
  }

  updateTable();

  $("#studentForm").submit(function(e) {
    e.preventDefault();
    $(".error").text("");
    let valid = true;
    const id = $("#studentId").val().trim();
    const last = $("#lastName").val().trim();
    const first = $("#firstName").val().trim();
    const email = $("#email").val().trim();

    if (!/^[0-9]+$/.test(id)) { $("#idError").text("Only numbers"); valid = false; }
    if (!/^[A-Za-z]+$/.test(last)) { $("#lastError").text("Letters only"); valid = false; }
    if (!/^[A-Za-z]+$/.test(first)) { $("#firstError").text("Letters only"); valid = false; }
    if (!/^[^@]+@[^@]+\.[^@]+$/.test(email)) { $("#emailError").text("Invalid email"); valid = false; }

    if (!valid) return;

    const newRow = `<tr>
      <td>${last}</td><td>${first}</td>
      <td></td><td></td><td></td><td></td><td></td><td></td>
      <td></td>
      <td class="edit-cell"><button type="button" class="edit-absence-btn" aria-label="Edit absences for ${last} ${first}">&#9998;</button></td>
      <td></td><td></td>
    </tr>`;

    $("#attendance tbody").append(newRow);
    updateTable();
    this.reset();
    $("#studentId").focus();
  });

  $("#showReport").click(function() {
    const total = $("#attendance tbody tr").length;
    let markedPresent = 0;
    let participated = 0;

    $("#attendance tbody tr").each(function() {
      const absValue = parseInt($(this).find("td:eq(8)").text(), 10) || 0;
      const parValue = parseInt($(this).find("td:eq(10)").text(), 10) || 0;

      if (absValue < 6) {
        markedPresent++;
      }
      participated += parValue;
    });

    $("#report").html(`
      <h2>Weekly snapshot</h2>
      <div class="stat-grid">
        <div class="stat-card">
          <p class="label">Total Students</p>
          <p class="value">${total}</p>
        </div>
        <div class="stat-card">
          <p class="label">Marked Present</p>
          <p class="value">${markedPresent}</p>
        </div>
        <div class="stat-card">
          <p class="label">Total Participations</p>
          <p class="value">${participated}</p>
        </div>
      </div>
      <p class="hint-text">Numbers refresh every time you click "Show Report".</p>
    `);
  });

  $("#attendance tbody")
    .on("mouseenter", "tr", function() {
      $(this).addClass("highlight");
    })
    .on("mouseleave", "tr", function() {
      $(this).removeClass("highlight");
    })
    .on("click", "tr", function() {
      const name = `${$(this).find("td:eq(0)").text()} ${$(this).find("td:eq(1)").text()}`;
      const absValue = parseInt($(this).find("td:eq(8)").text(), 10) || 0;
      showAbsenceInfo(name, absValue);
    });
  // Wire modal actions into the existing absence update flow.
  $("#attendance tbody").on("click", ".edit-absence-btn", function(e) {
    e.stopPropagation();
    const $row = $(this).closest("tr");
    openAbsenceModal($row);
  });

  $absenceModalForm.on("submit", function(e) {
    e.preventDefault();
    if (!$activeAbsRow) {
      setAbsenceError("");
      return;
    }

    const rawValue = $absenceModalInput.val();
    const trimmedValue = (rawValue ?? "").toString().trim();
    if (trimmedValue === "") {
      setAbsenceError("Absences cannot be empty.");
      return;
    }

    const nextValue = Number(trimmedValue);
    if (!Number.isInteger(nextValue) || nextValue < 0) {
      setAbsenceError("Absences must be an integer greater than or equal to 0.");
      return;
    }

    setAbsenceError("");
    $activeAbsRow.data("manualAbsOverride", nextValue);
    updateTable();
    closeAbsenceModal();
  });

  $absenceModalCancel.on("click", function() {
    setAbsenceError("");
    closeAbsenceModal();
  });

  $absenceModal.on("click", function(e) {
    if (e.target === this) {
      setAbsenceError("");
      closeAbsenceModal();
    }
  });

  $infoPopupOk.on("click", function() {
    closeInfoPopup();
  });

  $infoPopup.on("click", function(e) {
    if (e.target === this) {
      closeInfoPopup();
    }
  });

  $(document).on("keydown", function(e) {
    if (e.key === "Escape") {
      if ($absenceModal.hasClass("visible")) {
        setAbsenceError("");
        closeAbsenceModal();
      }
      if ($infoPopup.hasClass("visible")) {
        closeInfoPopup();
      }
    }
  });

  $("#highlightExcellent").click(function() {
    $("#attendance tbody tr").each(function() {
      const absValue = parseInt($(this).find("td:eq(8)").text(), 10) || 0;
      if (absValue < 3) {
        $(this)
          .stop(true, true)
          .fadeOut(120)
          .fadeIn(120)
          .css("background-color", "#d8f7ef");
      }
    });
  });

  $("#resetColors").click(function() {
    $("#attendance tbody tr").css("background-color", "");
    updateTable();
  });

  $("#searchName").on("keyup", function() {
    const value = $(this).val().toLowerCase();
    $("#attendance tbody tr").filter(function() {
      $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
    });
  });

  $("#sortAbs").click(function() {
    const rows = $("#attendance tbody tr").get();
    rows.sort(function(a, b) {
      const absA = parseInt($(a).find("td:eq(8)").text(), 10) || 0;
      const absB = parseInt($(b).find("td:eq(8)").text(), 10) || 0;
      return absA - absB;
    });
    $("#attendance tbody").append(rows);
    $("#sortMessage").text("Currently sorted by absences (ascending).");
  });

  $("#sortPar").click(function() {
    const rows = $("#attendance tbody tr").get();
    rows.sort(function(a, b) {
      const parA = parseInt($(a).find("td:eq(10)").text(), 10) || 0;
      const parB = parseInt($(b).find("td:eq(10)").text(), 10) || 0;
      return parB - parA;
    });
    $("#attendance tbody").append(rows);
    $("#sortMessage").text("Currently sorted by participation (descending).");
  });
});
