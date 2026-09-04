global.error_handle = (error)=>{
	try {
		var App = global.App;
		if(!App) var App = {};

		var status = 200;
		var data = error;

		// A failed axios request arrives here as an error carrying the server's
		// reply on `response`. This used to test `error.name == 'Error'`, which
		// worked only because 0.x built its rejection with
		// enhanceError(new Error(...)) and so left the name as 'Error'. 1.x
		// throws an AxiosError, whose name is 'AxiosError', so the test stopped
		// matching -- silently, because nothing throws: a 419 fell past this
		// branch and past the two below, kept status 200, and surfaced as the
		// toast "Request failed with status code 419" instead of the bounce to
		// the login page. 192 call sites hand this function the raw error.
		//
		// `response` is the discriminator axios documents and it is identical on
		// both lines. The 147 call sites that pass a SUCCESSFUL response instead
		// of an error are unaffected -- a response object has no `response` key
		// of its own -- and a network error, which has no response on either
		// version, now falls through to the message handling below rather than
		// throwing on `undefined.status` into the outer catch.
		if(isset(error.response) && isset(error.response.status)){
			status = error.response.status;
			data = error.response.data;
		}
		else if(isset(error.headers)){
			status = error.status;
			data = error.data;
		}else if(isset(error.responseJSON)){
			status = error.status;
			data = error.responseJSON;
		}

		if((status == 419 || status == 403 || status == 401 || status == 412)){
			window.location = `/store/public/auth/login/manager?link=${window.location.href}&error=${lang("Please login again")}`
			return;
		}

		if(data['result'] || data['status']=='success') return;
		
		if(data['message'] == 'ERROR_AUTHEN'){
			window.location = `/store/public/auth/login/manager?link=${window.location.href}&error=${lang(data['message'], data['data'])}` 
			return;
		}

		if(data['message'] == 'NO_LAB_SESSION'){
			window.location = '/';
			return;
		}

		
		showLog( lang(data['message'], data['data']), 'error' );
		
		
	}catch(err) {
		console.log(err);
		showLog(err.message, 'error');
	}
	
	 
}

global.showLog = (message, type)=>{
	if(typeof(addMessage) != "undefined"){
		if(type == 'error') type='danger';
		addMessage(type, message);
	}
	else if(typeof(toastr) != "undefined"){
		toastr[type](message)
	}
	else if(typeof(Swal) != "undefined"){
		Swal('', message, type);
	}
	console.log(message);
}
