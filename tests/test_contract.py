import pathlib
import unittest

ROOT = pathlib.Path(__file__).parents[1]


class ContractTests(unittest.TestCase):
    def test_single_entry_point_has_cli_and_web(self):
        source = (ROOT / "main.php").read_text()
        self.assertIn("PHP_SAPI === 'cli'", source)
        self.assertIn("function cli(", source)
        self.assertIn("function web(", source)
        for command in ["setup-demo", "status", "tables", "schema", "rows", "import", "transfer", "sql", "export"]:
            self.assertIn(f"'{command}'", source)

    def test_mutations_use_csrf_and_prepared_statements(self):
        source = (ROOT / "main.php").read_text()
        self.assertIn("hash_equals($_SESSION['csrf']", source)
        self.assertGreaterEqual(source.count("->prepare("), 7)
        self.assertIn("unsafe identifier", source)

    def test_php84_csv_escape_is_explicit(self):
        source = (ROOT / "main.php").read_text()
        self.assertIn("fgetcsv($handle, null, ',', '\"', '\\\\')", source)
        self.assertIn("fputcsv($handle, array_keys($rows[0]), ',', '\"', '\\\\')", source)

    def test_android_is_native_not_cordova(self):
        source = (ROOT / "android/app/src/main/java/org/dualdb/admin/MainActivity.java").read_text()
        self.assertIn("extends Activity", source)
        self.assertIn("setAllowFileAccess(false)", source)
        self.assertIn("uri.getUserInfo() != null", source)
        self.assertNotIn("cordova", source.lower())

    def test_android_sdk_bounds(self):
        build = (ROOT / "android/app/build.gradle").read_text()
        self.assertIn("compileSdk 35", build)
        self.assertIn("targetSdk 35", build)
        self.assertIn("minSdk 19", build)
        manifest = (ROOT / "android/app/src/main/AndroidManifest.xml").read_text()
        self.assertIn("android.permission.INTERNET", manifest)
        self.assertIn('android:resizeableActivity="true"', manifest)

    def test_funding_addresses_exact(self):
        support = (ROOT / "SUPPORT.md").read_text()
        expected = {"bc1qh474jpyw4malh0fmg2uy7n05ggtjvnjtcwhdne", "0x8fcC9C0d1FFCE17b1dEC91B299E56d66BC126Ba8", "D6qp2awRAHVo2VgincTAW5frhnJ9MBZcz4"}
        self.assertEqual({line.split("`", 2)[1] for line in support.splitlines() if line.startswith("- ")}, expected)
