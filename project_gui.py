"""Reusable PySide6 control panel for repository demos."""

from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

from PySide6.QtCore import QProcess, QTimer, QUrl
from PySide6.QtGui import QDesktopServices, QFont, QTextCursor
from PySide6.QtWidgets import (
    QApplication,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QMainWindow,
    QMessageBox,
    QPushButton,
    QPlainTextEdit,
    QVBoxLayout,
    QWidget,
)


ROOT = Path(__file__).resolve().parent
PROJECT = os.environ.get("PROJECT_TITLE", ROOT.name)


class ControlPanel(QMainWindow):
    def __init__(self) -> None:
        super().__init__()
        self.setWindowTitle(f"{PROJECT} Control Panel")
        self.resize(920, 620)
        self.process = QProcess(self)
        self.process.setWorkingDirectory(str(ROOT))
        self.process.setProcessChannelMode(QProcess.MergedChannels)
        self.process.readyReadStandardOutput.connect(self._drain)
        self.process.started.connect(lambda: self._status("Running"))
        self.process.finished.connect(self._finished)

        title = QLabel(PROJECT)
        title.setFont(QFont("Sans Serif", 20, QFont.Bold))
        self.status = QLabel("Ready")
        self.args = QLineEdit()
        self.args.setPlaceholderText("Optional CLI arguments")
        self.output = QPlainTextEdit()
        self.output.setReadOnly(True)
        self.output.setPlaceholderText("Command output appears here.")

        buttons = QHBoxLayout()
        for label, handler in (
            ("Run demo", self.run_demo),
            ("Run tests", self.run_tests),
            ("Install / repair", self.install),
            ("Build APK", self.build_apk),
            ("Stop", self.stop),
            ("README", self.open_readme),
        ):
            button = QPushButton(label)
            button.clicked.connect(handler)
            if label == "Build APK":
                button.setVisible((ROOT / "build-release.sh").exists() or (ROOT / "build_android.sh").exists())
            buttons.addWidget(button)

        layout = QVBoxLayout()
        layout.addWidget(title)
        layout.addWidget(self.status)
        layout.addWidget(self.args)
        layout.addLayout(buttons)
        layout.addWidget(self.output, 1)
        container = QWidget()
        container.setLayout(layout)
        self.setCentralWidget(container)

    def _status(self, text: str) -> None:
        self.status.setText(text)

    def _run(self, script: str, arguments: list[str] | None = None) -> None:
        if self.process.state() != QProcess.NotRunning:
            QMessageBox.information(self, PROJECT, "Stop the current command first.")
            return
        path = ROOT / script
        if not path.exists():
            QMessageBox.warning(self, PROJECT, f"{script} is not available.")
            return
        command = [str(path), *(arguments or [])]
        self.output.appendPlainText("\n$ " + " ".join(shlex.quote(part) for part in command))
        self.process.start("bash", command)

    def _drain(self) -> None:
        data = bytes(self.process.readAllStandardOutput()).decode("utf-8", "replace")
        self.output.moveCursor(QTextCursor.End)
        self.output.insertPlainText(data)
        self.output.ensureCursorVisible()

    def _finished(self, code: int, _status: QProcess.ExitStatus) -> None:
        self._drain()
        self._status(f"Finished with exit code {code}")

    def run_demo(self) -> None:
        self._run("cli.sh", shlex.split(self.args.text()))

    def run_tests(self) -> None:
        if (ROOT / "test.sh").exists():
            self._run("test.sh")
        else:
            self._run("cli.sh", ["--help"])

    def install(self) -> None:
        self._run("install.sh")

    def build_apk(self) -> None:
        self._run("build-release.sh" if (ROOT / "build-release.sh").exists() else "build_android.sh")

    def stop(self) -> None:
        if self.process.state() != QProcess.NotRunning:
            self.process.terminate()
            QTimer.singleShot(2500, self.process.kill)

    def open_readme(self) -> None:
        QDesktopServices.openUrl(QUrl.fromLocalFile(str(ROOT / "README.md")))

    def closeEvent(self, event) -> None:  # type: ignore[no-untyped-def]
        self.stop()
        event.accept()


def main() -> int:
    app = QApplication(sys.argv)
    window = ControlPanel()
    window.show()
    if os.environ.get("PROJECT_GUI_SMOKE") == "1":
        QTimer.singleShot(75, app.quit)
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())
