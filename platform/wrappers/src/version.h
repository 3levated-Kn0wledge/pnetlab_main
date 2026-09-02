/*
 * version.h — build identification for the fork's console wrappers.
 *
 * Deliberately NOT the original appliance binaries' version string. These are a
 * clean-room reimplementation written from a behavioural specification; claiming
 * the upstream version would misrepresent what an operator is running when they
 * ask a wrapper for -v.
 */
#ifndef WRAPPER_VERSION_H
#define WRAPPER_VERSION_H

#define WRAPPER_VERSION "1.0"
#define WRAPPER_VERSION_BLURB \
	"PNETLab fork console wrapper " WRAPPER_VERSION \
	" (clean-room reimplementation, BSD-free of upstream sources)"

#endif /* WRAPPER_VERSION_H */
