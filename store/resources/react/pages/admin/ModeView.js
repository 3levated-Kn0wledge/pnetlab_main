import React, { Component } from 'react';
import "./responsive/store.scss";

/**
 * System mode. One setting: whether the offline login page asks for a
 * captcha. The online/offline switches, the default-mode radio, the owner
 * lookup and the "keep alive" button all went with the online mode in
 * Phase 05 -- see Admin/ModeController.php and docs/OFFLINE-FIRST.md.
 */
class ModeView extends Component {
	constructor(props) {
		super(props);
		this.state = {
			offlineCaptcha: '',
		}
	}

	render() {
		return (
			<>
				<div className='box_padding container'>
					<br></br>
					<div className='row box_padding box_shadow'>
						<div className='col-md-6' style={{padding:5}}>
							<div><strong>{lang("Offline Mode")}</strong></div>
							<hr></hr>
							<div>
								<div className='box_flex' style={{margin:'15px 0px'}}>
									<div style={{ width: 100, fontWeight:'bold' }}>Captcha</div>
									<div style={{width:30}}>:</div>
									<i style={{ color: (this.state.offlineCaptcha == 1 ? '#4caf50' : 'red'), fontSize: 24 }} className={`button fa ${this.state.offlineCaptcha == 1 ? 'fa-toggle-on' : 'fa-toggle-off'}`} onClick={() => { this.setOfflineCaptcha() }}></i>
								</div>
							</div>
						</div>
					</div>
				</div>
			</>
		);
	}

	componentDidMount() {
		this.getModeData();
	}

	getModeData() {
		return axios.request({
			url: '/store/public/admin/mode/getModeData',
			method: 'post',
		})
			.then(response => {
				response = response['data'];
				if (!response['result']) {
					error_handle(response);
					return false;
				}
				this.setState({
					offlineCaptcha: get(response['data']['captcha'], ''),
				});
			})
			.catch(error => {
				console.log(error);
				error_handle(error)
				return false;
			})
	}

	setOfflineCaptcha() {
		var newState = this.state.offlineCaptcha == '1' ? '0' : '1';
		App.loading(true);
		return axios.request({
			url: '/store/public/admin/mode/setOfflineCaptcha',
			method: 'post',
			data: {
				'captcha': newState,
			}
		})
			.then(response => {
				App.loading(false);
				response = response['data'];
				if (!response['result']) {
					error_handle(response);
					return false;
				}
				this.setState({ offlineCaptcha: newState });
				Swal('Success', response['data'], 'success');
			})
			.catch(error => {
				App.loading(false);
				console.log(error);
				error_handle(error)
				return false;
			})
	}
}

export default ModeView
