/*
 * A stand-in for libusb-1.0 with one Brother P-Touch printer on the bus. ptouch-print runs
 * against it unchanged (LD_LIBRARY_PATH), so the contract test sees the exact bytes it sends
 * to the printer, without a printer.
 *
 *   FAKE_USB_PID     USB product id of the printer (hex, default 2061 = PT-P700)
 *   FAKE_USB_TAPE    tape width in mm the printer reports (default 12)
 *   FAKE_USB_ERROR   the two error bytes of the status (hex, default 0)
 *   FAKE_USB_SERIAL  USB serial number (default FAKE0001)
 *   FAKE_USB_OUT     file that gets one line of hex per bulk write (default: none)
 *
 * Build: gcc -shared -fPIC -Wl,-soname,libusb-1.0.so.0 -o libusb-1.0.so.0 fake-libusb.c
 */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <libusb-1.0/libusb.h>

static int device_token, handle_token;
static libusb_device *devices[2] = { (libusb_device *)&device_token, NULL };

static unsigned env_hex(const char *name, unsigned fallback)
{
	const char *v = getenv(name);
	return (v && *v) ? (unsigned)strtoul(v, NULL, 16) : fallback;
}

int LIBUSB_CALL libusb_init(libusb_context **ctx) { if (ctx) *ctx = NULL; return 0; }
void LIBUSB_CALL libusb_exit(libusb_context *ctx) { (void)ctx; }

ssize_t LIBUSB_CALL libusb_get_device_list(libusb_context *ctx, libusb_device ***list)
{
	(void)ctx;
	*list = devices;
	return 1;
}

void LIBUSB_CALL libusb_free_device_list(libusb_device **list, int unref) { (void)list; (void)unref; }

int LIBUSB_CALL libusb_get_device_descriptor(libusb_device *dev, struct libusb_device_descriptor *desc)
{
	(void)dev;
	memset(desc, 0, sizeof(*desc));
	desc->idVendor = 0x04f9;
	desc->idProduct = (uint16_t)env_hex("FAKE_USB_PID", 0x2061);
	desc->iSerialNumber = 3;
	return 0;
}

int LIBUSB_CALL libusb_open(libusb_device *dev, libusb_device_handle **handle)
{
	(void)dev;
	*handle = (libusb_device_handle *)&handle_token;
	return 0;
}

void LIBUSB_CALL libusb_close(libusb_device_handle *handle) { (void)handle; }

int LIBUSB_CALL libusb_get_string_descriptor_ascii(libusb_device_handle *handle, uint8_t index, unsigned char *data, int length)
{
	(void)handle; (void)index;
	const char *serial = getenv("FAKE_USB_SERIAL");
	if (!serial || !*serial) serial = "FAKE0001";
	snprintf((char *)data, (size_t)length, "%s", serial);
	return (int)strlen((char *)data);
}

uint8_t LIBUSB_CALL libusb_get_bus_number(libusb_device *dev) { (void)dev; return 1; }
uint8_t LIBUSB_CALL libusb_get_device_address(libusb_device *dev) { (void)dev; return 4; }
int LIBUSB_CALL libusb_kernel_driver_active(libusb_device_handle *h, int i) { (void)h; (void)i; return 0; }
int LIBUSB_CALL libusb_detach_kernel_driver(libusb_device_handle *h, int i) { (void)h; (void)i; return 0; }
int LIBUSB_CALL libusb_claim_interface(libusb_device_handle *h, int i) { (void)h; (void)i; return 0; }
int LIBUSB_CALL libusb_release_interface(libusb_device_handle *h, int i) { (void)h; (void)i; return 0; }
const char * LIBUSB_CALL libusb_error_name(int code) { (void)code; return "LIBUSB_ERROR_FAKE"; }

int LIBUSB_CALL libusb_bulk_transfer(libusb_device_handle *h, unsigned char endpoint, unsigned char *data,
	int length, int *transferred, unsigned int timeout)
{
	(void)h; (void)timeout;
	if (endpoint & 0x80) {
		/* status reply, Brother raster command reference "status information" */
		unsigned char status[32] = { 0x80, 0x20, 'B', '0', 0x71, '0', 0, 0 };
		unsigned error = env_hex("FAKE_USB_ERROR", 0);
		status[8] = (unsigned char)(error & 0xff);
		status[9] = (unsigned char)(error >> 8);
		const char *tape = getenv("FAKE_USB_TAPE");
		status[10] = (unsigned char)((tape && *tape) ? atoi(tape) : 12);
		status[11] = 0x01;	/* laminated tape */
		status[24] = 0x01;	/* white */
		status[25] = 0x08;	/* black */
		int n = length < 32 ? length : 32;
		memcpy(data, status, (size_t)n);
		*transferred = n;
		return 0;
	}
	const char *out = getenv("FAKE_USB_OUT");
	if (out && *out) {
		FILE *f = fopen(out, "a");
		if (f) {
			for (int i = 0; i < length; ++i) fprintf(f, "%02x", data[i]);
			fputc('\n', f);
			fclose(f);
		}
	}
	*transferred = length;
	return 0;
}
