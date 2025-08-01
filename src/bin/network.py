import json
import time


def parse_line(line):
    """Parse a line from /proc/net/dev into a dictionary of interface statistics."""
    line = line.strip()
    if ":" not in line:
        return None
    iface, stats_str = line.split(":", 1)
    iface = iface.strip()
    stats = stats_str.split()
    if len(stats) < 16:
        print(f"Bad # of columns ({len(stats)} instead of 16)!")
        return None
    try:
        return {
            "iface": iface,
            "rx_bytes": int(stats[0]),
            "rx_packets": int(stats[1]),
            "rx_errs": int(stats[2]),
            "rx_drop": int(stats[3]),
            "rx_fifo": int(stats[4]),
            "rx_frame": int(stats[5]),
            "rx_compressed": int(stats[6]),
            "rx_multicast": int(stats[7]),
            "tx_bytes": int(stats[8]),
            "tx_packets": int(stats[9]),
            "tx_errs": int(stats[10]),
            "tx_drop": int(stats[11]),
            "tx_fifo": int(stats[12]),
            "tx_colls": int(stats[13]),
            "tx_carrier": int(stats[14]),
            "tx_compressed": int(stats[15]),
        }
    except ValueError:
        print("Error parsing line")
        return None


def stats_read():
    """Read network statistics from /proc/net/dev."""
    try:
        with open("/proc/net/dev", "r") as f:
            lines = f.readlines()
    except IOError as e:
        print(f"Cannot open /proc/net/dev: {e}")
        return []
    stats = []
    # Skip the first two header lines
    for line in lines[2:]:
        parsed = parse_line(line)
        if parsed:
            stats.append(parsed)
    return stats


def stats_display(prev_stats, current_stats):
    """Calculate differences between two sets of statistics and write to a file."""
    prev_dict = {stat["iface"]: stat for stat in prev_stats}
    current_dict = {stat["iface"]: stat for stat in current_stats}
    diffs = []
    for iface in current_dict:
        if iface in prev_dict:
            prev = prev_dict[iface]
            current = current_dict[iface]
            diff = [
                iface,
                current["rx_bytes"] - prev["rx_bytes"],
                current["rx_packets"] - prev["rx_packets"],
                current["rx_errs"] - prev["rx_errs"],
                current["tx_bytes"] - prev["tx_bytes"],
                current["tx_packets"] - prev["tx_packets"],
                current["tx_errs"] - prev["tx_errs"],
            ]
            diffs.append(diff)
    try:
        with open("/home/xc_vm/tmp/logs/network", "w") as f:
            json.dump(diffs, f)
    except IOError as e:
        print(f"Cannot write to log file: {e}")


def main():
    """Main function to monitor network statistics continuously."""
    # Initial reading
    prev_stats = stats_read()
    # Continuous monitoring loop
    try:
        while True:
            time.sleep(2)  # Sleep for 2 seconds as per the original code
            current_stats = stats_read()
            if current_stats:  # Only proceed if reading was successful
                stats_display(prev_stats, current_stats)
                prev_stats = current_stats
    except KeyboardInterrupt:
        print("\nMonitoring stopped by user.")
        exit(0)


if __name__ == "__main__":
    main()
